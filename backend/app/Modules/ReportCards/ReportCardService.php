<?php

namespace App\Modules\ReportCards;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\GradeRecord;
use App\Models\ReportCard;
use App\Models\ReportCardItem;
use App\Models\ReportCardTemplate;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Modules\Attendance\AttendanceService;
use App\Modules\Audit\Audit;
use App\Modules\Grading\GradeCalculator;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportCardService
{
    public function __construct(private GradeCalculator $calc, private AttendanceService $attendance, private NotificationService $notify, private CurrentSchool $current) {}

    /** (Re)build a DRAFT card from APPROVED grades with the school's current rules. Issued cards are immutable. */
    public function generate(Student $student, Term $term, ?int $templateId = null, ?string $remarks = null): ReportCard
    {
        $enr = Enrollment::where('student_id', $student->id)->where('academic_year_id', $term->academic_year_id)->latest('id')->first();
        if (! $enr) {
            throw ValidationException::withMessages(['student' => ['دانش‌آموز در این سال تحصیلی ثبت‌نام نشده است.']]);
        }
        $existing = ReportCard::where('student_id', $student->id)->where('term_id', $term->id)->first();
        if ($existing && $existing->status === 'issued') {
            throw ValidationException::withMessages(['report_card' => ['کارنامهٔ صادرشده قابل بازسازی نیست؛ ابتدا باطل کنید.']]);
        }
        $rules = $this->calc->rules();
        $records = GradeRecord::where('student_id', $student->id)->where('term_id', $term->id)->where('status', 'approved')->get()->groupBy('subject_id');
        $section = Section::find($enr->section_id);
        $subjectIds = Subject::where(fn ($q) => $q->where('grade_id', $section->grade_id)->orWhereNull('grade_id'))->pluck('id')
            ->merge($records->keys())->unique();

        $scores = [];
        foreach ($subjectIds as $sid) {
            $scores[$sid] = isset($records[$sid]) ? $this->calc->subjectScore($records[$sid], $rules) : null;
        }
        $res = $this->calc->termResult($scores, $rules);

        return DB::transaction(function () use ($student, $term, $enr, $templateId, $remarks, $scores, $res, $rules, $existing) {
            $card = $existing ?? new ReportCard(['student_id' => $student->id, 'term_id' => $term->id]);
            $card->fill([
                'section_id' => $enr->section_id, 'template_id' => $templateId ?? ReportCardTemplate::where('is_default', true)->value('id'),
                'status' => 'draft', 'average' => $res['average'], 'result' => $res['result'], 'remarks' => $remarks ?? $existing?->remarks,
                'attendance_summary' => $this->attendance->summary($student->id, $term->starts_on->toDateString(), $term->ends_on->toDateString()),
                'formula_snapshot' => $rules,
            ])->save();
            ReportCardItem::where('report_card_id', $card->id)->delete();
            $coefs = Subject::whereIn('id', array_keys($scores))->pluck('coefficient', 'id');
            foreach ($scores as $sid => $score) {
                ReportCardItem::create(['report_card_id' => $card->id, 'subject_id' => $sid, 'score' => $score, 'coefficient' => $coefs[$sid] ?? 1]);
            }

            return $card->load('items');
        });
    }

    public function generateForSection(Section $section, Term $term): int
    {
        $n = 0;
        foreach (Student::whereIn('id', Enrollment::where('section_id', $section->id)->where('status', 'active')->select('student_id'))->get() as $stu) {
            $this->generate($stu, $term);
            $n++;
        }

        return $n;
    }

    public function issue(ReportCard $card, User $by): ReportCard
    {
        if ($card->status === 'issued') {
            return $card;
        }
        $pending = GradeRecord::where('student_id', $card->student_id)->where('term_id', $card->term_id)->where('status', 'draft')->count();
        if ($pending > 0) {
            throw ValidationException::withMessages(['report_card' => ["{$pending} نمره هنوز تأیید نشده است؛ ابتدا نمرات را تأیید کنید."]]);
        }
        if ($card->average === null) {
            throw ValidationException::withMessages(['report_card' => ['برای این دانش‌آموز نمرهٔ تأییدشده‌ای وجود ندارد.']]);
        }
        $card->update(['status' => 'issued', 'issued_by' => $by->id, 'issued_at' => now()]);
        $stu = Student::find($card->student_id);
        $users = collect([$stu->user_id])->merge(Recipients::guardianUsers($stu->id))->filter();
        $this->notify->send($users, 'reportcard.issued', "reportcard:{$card->id}", 'کارنامه صادر شد', null, ['report_card_id' => $card->id]);
        Audit::record('reportcard.issued', $card, null, ['average' => $card->average, 'result' => $card->result]);

        return $card;
    }

    public function revoke(ReportCard $card, User $by, string $reason): ReportCard
    {
        $card->update(['status' => 'revoked']);
        Audit::record('reportcard.revoked', $card, ['status' => 'issued'], ['reason' => $reason]);

        return $card;
    }

    /** Persian PDF (mPDF: real Arabic-script shaping + RTL) with the school's template. */
    public function pdf(ReportCard $card): string
    {
        $card->load('items');
        $school = School::find($this->current->id());
        $stu = Student::find($card->student_id);
        $term = Term::find($card->term_id);
        $tpl = $card->template_id ? ReportCardTemplate::find($card->template_id) : null;
        $year = \App\Models\AcademicYear::find($term->academic_year_id);
        $section = Section::with('grade')->find($card->section_id);
        $subjects = Subject::whereIn('id', $card->items->pluck('subject_id'))->pluck('name', 'id');
        $fa = fn ($v) => strtr((string) $v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', '.' => '٫']);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $resultFa = ['passed' => 'قبول', 'failed' => 'مردود', 'makeup' => 'نیازمند جبرانی'][$card->result] ?? '—';
        $att = $card->attendance_summary ?? [];

        $rows = '';
        foreach ($card->items as $i => $it) {
            $rows .= '<tr><td>'.$fa($i + 1).'</td><td class="r">'.$e($subjects[$it->subject_id] ?? '').'</td><td>'.$fa($it->coefficient + 0).'</td><td>'.($it->score === null ? '—' : $fa(rtrim(rtrim(number_format($it->score, 2, '.', ''), '0'), '.'))).'</td></tr>';
        }
        $sign = '';
        foreach (($tpl?->signatures ?? ['مدیر مدرسه', 'معلم']) as $s) {
            $sign .= '<td class="sig">'.$e($s).'<br><br>................</td>';
        }
        $html = '<html dir="rtl"><body style="font-family:vazirmatn;direction:rtl;text-align:right;font-size:11pt;color:#1b2a41">'
            .'<style>table{border-collapse:collapse;width:100%}td,th{border:1px solid #c9d6e8;padding:6px;text-align:center}th{background:#eaf2fb}.r{text-align:right}.sig{border:none;padding-top:30px}h1{color:#1f5fa8;font-size:18pt;text-align:center;margin:0}.sub{text-align:center;color:#58708f}</style>'
            .'<h1>'.$e($school->name).'</h1><p class="sub">'.$e($tpl?->header ?: 'کارنامه تحصیلی').' — '.$e($year->title).' — '.$e($term->title).'</p>'
            .'<table><tr><td class="r">نام و نام خانوادگی: '.$e($stu->first_name.' '.$stu->last_name).'</td><td class="r">شمارهٔ دانش‌آموزی: '.$fa($e($stu->student_code)).'</td></tr>'
            .'<tr><td class="r">پایه: '.$e($section->grade->name ?? '').'</td><td class="r">کلاس: '.$e($section->name).'</td></tr></table><br>'
            .'<table><tr><th>ردیف</th><th>درس</th><th>ضریب</th><th>نمره</th></tr>'.$rows.'</table><br>'
            .'<table><tr><td class="r">معدل: <b>'.($card->average === null ? '—' : $fa(number_format($card->average, 2, '.', ''))).'</b></td><td class="r">نتیجه: <b>'.$e($resultFa).'</b></td></tr>'
            .'<tr><td class="r">غیبت: '.$fa($att['absent'] ?? 0).'</td><td class="r">تأخیر: '.$fa($att['late'] ?? 0).'</td></tr></table>'
            .($card->remarks ? '<p class="r">توضیحات: '.$e($card->remarks).'</p>' : '')
            .'<br><table style="border:none"><tr>'.$sign.'</tr></table>'
            .($tpl?->footer ? '<p class="sub">'.$e($tpl->footer).'</p>' : '')
            .($card->status !== 'issued' ? '<p class="sub" style="color:#b42318">پیش‌نویس — معتبر نیست</p>' : '')
            .'</body></html>';

        $defaults = (new \Mpdf\Config\ConfigVariables)->getDefaults();
        $fonts = (new \Mpdf\Config\FontVariables)->getDefaults();
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('app/mpdf'),
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + ['vazirmatn' => ['R' => 'Vazirmatn-Regular.ttf', 'B' => 'Vazirmatn-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
            'default_font' => 'vazirmatn', 'autoLangToFont' => false, 'autoScriptToLang' => false,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}

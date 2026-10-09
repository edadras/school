<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\GradeRecord;
use App\Models\ReportCard;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentNote;
use App\Models\Submission;
use App\Models\Subject;
use App\Models\Term;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Administration\Http\SchoolAdminController;
use App\Modules\Attendance\AttendanceService;
use App\Modules\Audit\Audit;
use App\Modules\Grading\GradeCalculator;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Continuous student file across academic years, year-end promotion and erasure (anonymisation). */
class StudentFileController extends Controller
{
    public function __construct(private Access $access, private GradeCalculator $calc, private AttendanceService $attendance) {}

    /** The signed-in student's own file. */
    public function mine(Request $request): JsonResponse
    {
        $id = $this->access->ownStudentId($request->user());
        abort_unless($id, 404);

        return $this->dossier($request, $id);
    }

    /** پرونده تحصیلی: only approved grades and issued report cards; private staff notes follow the notes policy. */
    public function dossier(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        abort_unless($this->access->canViewStudent($request->user(), $studentId), 404);
        $staff = $this->access->isStaff($request->user()) || $this->access->can($request->user(), 'grades.enter');

        $enrollments = Enrollment::where('student_id', $studentId)->orderBy('academic_year_id')->get();
        $sections = Section::with(['grade:id,name', 'year:id,title'])->whereIn('id', $enrollments->pluck('section_id'))->get()->keyBy('id');
        $history = $enrollments->map(fn ($e) => [
            'academic_year' => $sections[$e->section_id]->year->title ?? null, 'grade' => $sections[$e->section_id]->grade->name ?? null,
            'section' => $sections[$e->section_id]->name ?? null, 'status' => $e->status, 'enrolled_on' => $e->enrolled_on, 'ended_on' => $e->ended_on,
        ])->values();

        $rules = $this->calc->rules();
        $subjects = Subject::pluck('name', 'id');
        $records = GradeRecord::where('student_id', $studentId)->where('status', 'approved')->get()->groupBy('term_id');
        $terms = Term::with('year:id,title')->whereIn('id', $records->keys())->orderBy('starts_on')->get()->map(function ($t) use ($records, $rules, $subjects) {
            $scores = $records[$t->id]->groupBy('subject_id')->map(fn ($r) => $this->calc->subjectScore($r, $rules))->all();

            return ['term' => $t->title, 'academic_year' => $t->year->title ?? null, 'subjects' => collect($scores)->mapWithKeys(fn ($v, $k) => [$subjects[$k] ?? $k => $v]),
                'result' => $this->calc->termResult($scores, $rules)];
        })->values();

        $submissions = Submission::where('student_id', $studentId)->whereNotNull('submitted_at')->get();
        $cards = ReportCard::where('student_id', $studentId)->where('status', 'issued')->orderByDesc('id')->get(['id', 'term_id', 'average', 'result', 'issued_at']);

        $data = [
            'student' => $student->only(['id', 'first_name', 'last_name', 'student_code', 'birth_date', 'status']),
            'enrollments' => $history, 'terms' => $terms, 'report_cards' => $cards,
            'attendance' => $this->attendance->summary($studentId),
            'assignments' => ['submitted' => $submissions->count(), 'late' => $submissions->where('is_late', true)->count(),
                'average_score' => $submissions->whereNotNull('score')->avg('score') !== null ? round((float) $submissions->whereNotNull('score')->avg('score'), 2) : null],
            'notes' => app(SchoolAdminController::class)->notes($request, $studentId)->getData(true)['data'],
        ];
        $staff && Audit::record('student_file.viewed', $student, null, ['student_id' => $studentId]);

        return response()->json(['data' => $data]);
    }

    /** Year-end promotion of a whole class (optionally only students who passed) into a section of another academic year. */
    public function promote(Request $request): JsonResponse
    {
        $d = $request->validate([
            'from_section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'to_section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'only_passed' => ['sometimes', 'boolean'], 'student_ids' => ['sometimes', 'array'], 'student_ids.*' => ['integer'],
        ]);
        $from = Section::findOrFail($d['from_section_id']);
        $to = Section::findOrFail($d['to_section_id']);
        if ($from->academic_year_id === $to->academic_year_id) {
            throw ValidationException::withMessages(['to_section_id' => ['کلاس مقصد باید در سال تحصیلی دیگری باشد.']]);
        }

        $out = DB::transaction(function () use ($d, $from, $to, $request) {
            $to = Section::whereKey($to->id)->lockForUpdate()->firstOrFail();
            $free = $to->capacity - Enrollment::where('section_id', $to->id)->where('status', 'active')->count();
            $promoted = [];
            $skipped = [];
            $enrollments = Enrollment::where('section_id', $from->id)->where('status', 'active')
                ->when(! empty($d['student_ids']), fn ($q) => $q->whereIn('student_id', $d['student_ids']))->get();

            foreach ($enrollments as $e) {
                if (Enrollment::where('student_id', $e->student_id)->where('academic_year_id', $to->academic_year_id)->exists()) {
                    $skipped[] = ['student_id' => $e->student_id, 'reason' => 'در سال مقصد ثبت‌نام دارد'];

                    continue;
                }
                if (! empty($d['only_passed'])) {
                    $card = ReportCard::where('student_id', $e->student_id)->where('status', 'issued')->whereIn('term_id', Term::where('academic_year_id', $from->academic_year_id)->select('id'))->orderByDesc('id')->first();
                    if (! $card || $card->result !== 'passed') {
                        $skipped[] = ['student_id' => $e->student_id, 'reason' => $card ? 'نتیجهٔ کارنامه قبول نیست' : 'کارنامهٔ صادرشده ندارد'];

                        continue;
                    }
                }
                if ($free <= 0) {
                    $skipped[] = ['student_id' => $e->student_id, 'reason' => 'ظرفیت کلاس مقصد تکمیل است'];

                    continue;
                }
                $e->update(['status' => 'promoted', 'ended_on' => today()]);
                Enrollment::create(['student_id' => $e->student_id, 'section_id' => $to->id, 'academic_year_id' => $to->academic_year_id, 'enrolled_on' => today()]);
                $free--;
                $promoted[] = $e->student_id;
            }
            Audit::record('enrollment.promoted', $from, null, ['from' => $from->id, 'to' => $to->id, 'count' => count($promoted), 'skipped' => count($skipped)]);

            return ['promoted' => count($promoted), 'skipped' => $skipped];
        });

        return response()->json($out);
    }

    /** Erasure: personal data is removed, academic records are kept without identity (retention of grades is a school duty). */
    public function anonymize(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        $request->validate(['confirm_code' => ['required', 'string']]);
        if ($request->input('confirm_code') !== $student->student_code) {
            throw ValidationException::withMessages(['confirm_code' => ['برای تأیید، کد دانش‌آموز را دقیقاً وارد کنید.']]);
        }
        DB::transaction(function () use ($student) {
            if ($student->user_id) {
                $u = \App\Models\User::find($student->user_id);
                if ($u) {
                    $u->forceFill(['name' => 'حذف‌شده', 'email' => null, 'phone' => null, 'national_code' => null, 'status' => 'disabled'])->save();
                    $u->tokens()->delete();
                }
            }
            $student->forceFill(['first_name' => 'حذف‌شده', 'last_name' => '—', 'birth_date' => null, 'custom_fields' => null, 'status' => 'archived', 'student_code' => 'anon-'.$student->id, 'user_id' => null])->save();
            StudentGuardian::where('student_id', $student->id)->update(['status' => 'revoked']);
            StudentNote::where('student_id', $student->id)->update(['body' => null, 'title' => 'حذف‌شده']);
            Audit::record('student.anonymized', $student, null, ['student_id' => $student->id]);
        });

        return response()->json(['message' => 'اطلاعات هویتی دانش‌آموز حذف شد؛ سوابق نمره بدون هویت نگه‌داری می‌شود.']);
    }
}

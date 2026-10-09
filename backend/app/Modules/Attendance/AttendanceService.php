<?php

namespace App\Modules\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\LessonSession;
use App\Models\Student;
use App\Models\User;
use App\Modules\Files\SettingsRepository;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use Illuminate\Support\Carbon;

class AttendanceService
{
    public function __construct(private SettingsRepository $settings, private NotificationService $notify) {}

    public static function keyFor(LessonSession $s): string
    {
        return $s->timetable_entry_id ? "e{$s->timetable_entry_id}:{$s->on_date->toDateString()}" : "s{$s->id}";
    }

    /** A student's first join defines present/late. Teacher-confirmed rows are never overwritten by automation. */
    public function recordJoin(LessonSession $s, Student $student, ?Carbon $at = null): AttendanceRecord
    {
        $at ??= now();
        $grace = (int) $this->settings->get('attendance.late_after_minutes', 5);
        $late = max(0, (int) floor($s->scheduled_start->diffInMinutes($at, false)));
        $status = $late > $grace ? 'late' : 'present';

        $rec = AttendanceRecord::where('student_id', $student->id)->where('lesson_key', self::keyFor($s))->first();
        if ($rec && $rec->confirmed) {
            return $rec;
        }
        // A join after an auto 'absent' (class closed early) or first join: upgrade; a later join never downgrades 'present'.
        if ($rec && in_array($rec->status, ['present', 'late'], true)) {
            return $rec;
        }
        $data = ['status' => $status, 'minutes_late' => $status === 'late' ? $late : 0, 'source' => 'auto', 'lesson_session_id' => $s->id,
            'section_id' => $s->section_id, 'subject_id' => $s->subject_id, 'on_date' => $s->on_date];
        $rec = $rec ? tap($rec)->update($data) : AttendanceRecord::create($data + ['student_id' => $student->id, 'lesson_key' => self::keyFor($s)]);
        $status === 'late' && $this->notifyGuardians($student, 'attendance.late', 'تأخیر', "{$student->first_name} {$student->last_name} با {$late} دقیقه تأخیر وارد کلاس شد.", $rec);

        return $rec;
    }

    /** At the end of a class, enrolled students with no record are absent (teacher can still correct). */
    public function closeSession(LessonSession $s): int
    {
        $n = 0;
        $students = Student::whereIn('id', Enrollment::where('section_id', $s->section_id)->where('status', 'active')->select('student_id'))->get();
        foreach ($students as $stu) {
            $exists = AttendanceRecord::where('student_id', $stu->id)->where('lesson_key', self::keyFor($s))->exists();
            if (! $exists) {
                $rec = AttendanceRecord::create([
                    'student_id' => $stu->id, 'section_id' => $s->section_id, 'subject_id' => $s->subject_id, 'lesson_session_id' => $s->id,
                    'lesson_key' => self::keyFor($s), 'on_date' => $s->on_date, 'status' => 'absent', 'source' => 'auto',
                ]);
                $this->notifyGuardians($stu, 'attendance.absent', 'غیبت', "{$stu->first_name} {$stu->last_name} در کلاس امروز غایب بود.", $rec);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Teacher/staff correction or physical-class roll call. $items: [{student_id,status,minutes_late?,note?}].
     * Marks rows confirmed so automation never overrides them.
     */
    public function recordManual(User $by, int $sectionId, ?int $subjectId, string $lessonKey, string $date, array $items, ?int $sessionId = null): int
    {
        $valid = Enrollment::where('section_id', $sectionId)->where('status', 'active')->pluck('student_id')->all();
        $count = 0;
        foreach ($items as $it) {
            if (! in_array($it['student_id'], $valid, true)) {
                continue; // silently ignore students outside this class
            }
            $rec = AttendanceRecord::updateOrCreate(
                ['student_id' => $it['student_id'], 'lesson_key' => $lessonKey],
                ['section_id' => $sectionId, 'subject_id' => $subjectId, 'lesson_session_id' => $sessionId, 'on_date' => $date, 'status' => $it['status'],
                    'minutes_late' => $it['status'] === 'late' ? (int) ($it['minutes_late'] ?? 0) : 0, 'source' => 'teacher', 'confirmed' => true,
                    'recorded_by' => $by->id, 'note' => $it['note'] ?? null],
            );
            if ($rec->wasRecentlyCreated || $rec->wasChanged('status')) {
                $stu = Student::find($it['student_id']);
                in_array($it['status'], ['absent', 'late'], true) && $this->notifyGuardians($stu, 'attendance.'.$it['status'],
                    $it['status'] === 'absent' ? 'غیبت' : 'تأخیر', "{$stu->first_name} {$stu->last_name}: ".($it['status'] === 'absent' ? 'غایب' : 'با تأخیر'), $rec);
            }
            $count++;
        }

        return $count;
    }

    private function notifyGuardians(Student $stu, string $type, string $title, string $body, AttendanceRecord $rec): void
    {
        $users = collect(Recipients::guardianUsers($stu->id))->push($stu->user_id)->filter();
        // one notification per student+lesson+status: corrections of the same status don't re-notify.
        $this->notify->send($users, $type, "{$type}:{$rec->student_id}:{$rec->lesson_key}", $title, $body, ['student_id' => $stu->id, 'date' => $rec->on_date?->toDateString()]);
    }

    public function summary(int $studentId, ?string $from = null, ?string $to = null): array
    {
        $q = AttendanceRecord::where('student_id', $studentId)->when($from, fn ($q) => $q->whereDate('on_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('on_date', '<=', $to));
        $by = $q->selectRaw('status, count(*) c, sum(minutes_late) m')->groupBy('status')->get()->keyBy('status');

        return ['present' => (int) ($by['present']->c ?? 0), 'late' => (int) ($by['late']->c ?? 0), 'absent' => (int) ($by['absent']->c ?? 0),
            'excused' => (int) ($by['excused']->c ?? 0), 'minutes_late' => (int) ($by['late']->m ?? 0)];
    }
}

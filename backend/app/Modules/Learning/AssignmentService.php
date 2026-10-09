<?php

namespace App\Modules\Learning;

use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Modules\Academics\Terms;
use App\Modules\Audit\Audit;
use App\Modules\Files\FileService;
use App\Modules\Grading\GradeService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private Access $access, private FileService $files, private NotificationService $notify,
        private GradeService $grades, private CurrentSchool $current,
    ) {}

    public function create(User $teacherUser, array $d, array $fileIds = []): Assignment
    {
        abort_unless($this->access->canTeach($teacherUser, $d['section_id'], $d['subject_id']), 403, 'شما معلم این درس در این کلاس نیستید.');

        return DB::transaction(function () use ($teacherUser, $d, $fileIds) {
            $teacherId = $this->access->teacherId($teacherUser)
                ?? \App\Models\TeacherAssignment::where('section_id', $d['section_id'])->where('subject_id', $d['subject_id'])->value('teacher_id');
            abort_unless($teacherId, 422, 'معلمی برای این درس تعیین نشده است.');
            $a = Assignment::create($d + ['teacher_id' => $teacherId, 'status' => 'draft']);
            foreach ($fileIds as $fid) {
                $this->files->claim($fid, $teacherUser, 'assignment', $a->id);
                AssignmentAttachment::create(['assignment_id' => $a->id, 'file_id' => $fid]);
            }
            Audit::record('assignment.created', $a, null, $a->only(['title', 'section_id', 'subject_id']));

            return $a;
        });
    }

    public function publish(Assignment $a): Assignment
    {
        if ($a->status === 'published') {
            return $a;
        }
        $a->update(['status' => 'published', 'publish_at' => $a->publish_at ?? now()]);
        $users = Recipients::forSection($this->current->id(), $a->section_id);
        $this->notify->send($users, 'assignment.new', "assignment-new:{$a->id}", 'تکلیف جدید: '.$a->title,
            $a->due_at ? 'مهلت: '.$a->due_at->toDateTimeString() : null, ['assignment_id' => $a->id]);

        return $a;
    }

    /** Visible to students only when published and its publish time has come. */
    public function isOpenForStudents(Assignment $a): bool
    {
        return $a->status !== 'draft' && ($a->publish_at === null || $a->publish_at->lte(now()));
    }

    public function markViewed(Assignment $a, int $studentId): Submission
    {
        $s = Submission::firstOrCreate(['assignment_id' => $a->id, 'student_id' => $studentId], ['status' => 'viewed', 'viewed_at' => now()]);

        return $s;
    }

    public function saveDraft(Assignment $a, int $studentId, array $payload): Submission
    {
        $this->assertOpen($a);
        abort_unless($a->allow_draft, 422, 'ذخیرهٔ موقت برای این تکلیف مجاز نیست.');
        $s = $this->markViewed($a, $studentId);
        $this->assertEditable($s, $a);
        $this->validatePayload($a, $payload);

        $s->update([
            'text_answer' => $payload['text_answer'] ?? $s->text_answer, 'drawing' => $payload['drawing'] ?? $s->drawing,
            'math' => $payload['math'] ?? $s->math, 'draft_saved_at' => now(),
            'status' => in_array($s->status, ['viewed', 'in_progress'], true) ? 'in_progress' : $s->status,
        ]);

        return $s->refresh();
    }

    public function submit(Assignment $a, User $student, int $studentId, array $payload, array $fileIds = []): Submission
    {
        $this->assertOpen($a);

        return DB::transaction(function () use ($a, $student, $studentId, $payload, $fileIds) {
            $this->markViewed($a, $studentId);
            $s = Submission::where('assignment_id', $a->id)->where('student_id', $studentId)->lockForUpdate()->first();
            $this->assertEditable($s, $a);
            $this->validatePayload($a, $payload, $fileIds);

            $late = $a->due_at && now()->gt($a->due_at);
            if ($late && ! $a->allow_late) {
                throw ValidationException::withMessages(['submission' => ['مهلت ارسال این تکلیف به پایان رسیده است.']]);
            }
            $text = $payload['text_answer'] ?? $s->text_answer;
            $drawing = $payload['drawing'] ?? $s->drawing;
            $math = $payload['math'] ?? $s->math;
            if (blank($text) && blank($drawing) && blank($math) && ! $fileIds && ! $s->files()->exists()) {
                throw ValidationException::withMessages(['submission' => ['پاسخ خالی قابل ارسال نیست.']]);
            }

            $attempt = $s->attempt + 1;
            $s->update([
                'text_answer' => $text, 'drawing' => $drawing, 'math' => $math, 'attempt' => $attempt,
                'status' => $late ? 'late' : 'submitted', 'is_late' => $late, 'submitted_at' => now(),
                'score' => null, 'graded_at' => null, 'graded_by' => null,
            ]);
            foreach ($fileIds as $fid) {
                $f = $this->files->claim($fid, $student, 'submission', $s->id);
                SubmissionFile::create(['submission_id' => $s->id, 'file_id' => $fid, 'kind' => $this->kindOf($f->mime)]);
            }
            // Immutable snapshot of this attempt for the teacher's history view.
            SubmissionVersion::create(['submission_id' => $s->id, 'attempt' => $attempt, 'snapshot' => [
                'text_answer' => $text, 'drawing' => $drawing, 'math' => $math, 'is_late' => $late,
                'files' => $s->files()->pluck('file_id')->all(), 'submitted_at' => now()->toIso8601String(),
            ]]);
            $teacherUser = Recipients::teacherUser($a->teacher_id);
            $teacherUser && $this->notify->send([$teacherUser], 'submission.new', "submission:{$s->id}:{$attempt}", 'پاسخ جدید برای: '.$a->title, null, ['submission_id' => $s->id]);

            return $s->refresh();
        });
    }

    /** Teacher: score + feedback; $outcome = finalize | return | review (save without finalising). */
    public function grade(Submission $s, Assignment $a, User $teacher, array $d, string $outcome): Submission
    {
        abort_unless($this->access->canTeach($teacher, $a->section_id, $a->subject_id), 403);
        abort_if(in_array($s->status, ['viewed', 'in_progress'], true), 422, 'دانش‌آموز هنوز پاسخی ارسال نکرده است.');

        return DB::transaction(function () use ($s, $a, $teacher, $d, $outcome) {
            $score = $d['score'] ?? $s->score;
            if ($outcome === 'finalize' && $score === null) {
                throw ValidationException::withMessages(['score' => ['برای نهایی‌کردن، نمره الزامی است.']]);
            }
            if ($score !== null && ($score < 0 || $score > $a->max_score)) {
                throw ValidationException::withMessages(['score' => ["نمره باید بین ۰ و {$a->max_score} باشد."]]);
            }
            $status = ['finalize' => 'finalized', 'return' => 'needs_revision', 'review' => 'under_review'][$outcome];
            if ($outcome === 'return' && ! $a->allow_resubmit) {
                throw ValidationException::withMessages(['outcome' => ['ارسال مجدد برای این تکلیف مجاز نیست.']]);
            }
            $s->update([
                'status' => $status, 'score' => $score, 'feedback' => $d['feedback'] ?? $s->feedback,
                'rubric_scores' => $d['rubric_scores'] ?? $s->rubric_scores, 'graded_by' => $teacher->id, 'graded_at' => now(),
            ]);

            if ($outcome === 'finalize') {
                $term = $a->term_id ? \App\Models\Term::find($a->term_id) : Terms::at();
                abort_unless($term, 422, 'ترم تحصیلی تعریف نشده است.');
                $this->grades->record($teacher, [
                    'student_id' => $s->student_id, 'section_id' => $a->section_id, 'subject_id' => $a->subject_id, 'term_id' => $term->id,
                    'kind' => 'assignment', 'source_type' => 'submission', 'source_id' => $s->id, 'title' => $a->title,
                    'score' => $score, 'max_score' => $a->max_score,
                ], $d['reason'] ?? null);
            }

            $stu = \App\Models\Student::find($s->student_id);
            $users = collect([$stu?->user_id])->merge(\App\Modules\Scheduling\Recipients::guardianUsers($s->student_id))->filter();
            $this->notify->send($users, $outcome === 'return' ? 'assignment.returned' : 'assignment.graded', "graded:{$s->id}:{$s->attempt}:{$outcome}",
                $outcome === 'return' ? 'تکلیف برای اصلاح بازگردانده شد: '.$a->title : 'بازخورد تکلیف: '.$a->title, null, ['submission_id' => $s->id]);
            Audit::record('submission.'.$outcome, $s, null, ['score' => $score]);

            return $s->refresh();
        });
    }

    private function assertOpen(Assignment $a): void
    {
        abort_unless($this->isOpenForStudents($a), 404);
        abort_if($a->status === 'closed', 422, 'این تکلیف بسته شده است.');
    }

    private function assertEditable(Submission $s, Assignment $a): void
    {
        if ($s->status === 'finalized') {
            throw ValidationException::withMessages(['submission' => ['این تکلیف نهایی شده و قابل ویرایش نیست.']]);
        }
        if (in_array($s->status, ['submitted', 'late', 'under_review'], true) && ! $a->allow_resubmit) {
            throw ValidationException::withMessages(['submission' => ['ارسال مجدد برای این تکلیف مجاز نیست.']]);
        }
    }

    private function validatePayload(Assignment $a, array $p, array $fileIds = []): void
    {
        $types = $a->answer_types;
        if (! in_array('text', $types, true) && ! empty($p['text_answer'])) {
            throw ValidationException::withMessages(['text_answer' => ['پاسخ متنی برای این تکلیف مجاز نیست.']]);
        }
        if (! in_array('drawing', $types, true) && ! empty($p['drawing'])) {
            throw ValidationException::withMessages(['drawing' => ['پاسخ با رسم برای این تکلیف مجاز نیست.']]);
        }
        if (! in_array('math', $types, true) && ! empty($p['math'])) {
            throw ValidationException::withMessages(['math' => ['پاسخ ریاضی برای این تکلیف مجاز نیست.']]);
        }
        foreach ($fileIds as $fid) {
            $f = \App\Models\StoredFile::find($fid);
            $kind = $f ? $this->kindOf($f->mime) : null;
            $ok = match ($kind) {
                'audio' => in_array('audio', $types, true), 'video' => in_array('video', $types, true),
                default => in_array('file', $types, true),
            };
            if (! $ok) {
                throw ValidationException::withMessages(['file_ids' => ['نوع فایل برای این تکلیف مجاز نیست.']]);
            }
        }
    }

    private function kindOf(string $mime): string
    {
        return str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'file'));
    }
}

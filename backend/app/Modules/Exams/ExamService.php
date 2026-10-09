<?php

namespace App\Modules\Exams;

use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Modules\Academics\Terms;
use App\Modules\Audit\Audit;
use App\Modules\Grading\GradeService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamService
{
    /** Clock-skew/latency allowance when accepting the last autosave of an attempt. */
    private const GRACE_SECONDS = 10;

    public function __construct(
        private Access $access, private NotificationService $notify, private GradeService $grades, private CurrentSchool $current,
    ) {}

    // ------------------------------------------------------------------ authoring
    public function create(User $by, array $d, array $items): Exam
    {
        abort_unless($this->access->canTeach($by, $d['section_id'], $d['subject_id']), 403, 'شما معلم این درس در این کلاس نیستید.');
        $teacherId = $this->access->teacherId($by) ?? \App\Models\TeacherAssignment::where('section_id', $d['section_id'])->where('subject_id', $d['subject_id'])->value('teacher_id');
        abort_unless($teacherId, 422, 'معلمی برای این درس تعیین نشده است.');
        $windowMin = (int) \Illuminate\Support\Carbon::parse($d['start_at'])->diffInMinutes(\Illuminate\Support\Carbon::parse($d['end_at']));
        if ($d['duration_minutes'] > $windowMin) {
            throw ValidationException::withMessages(['duration_minutes' => ['مدت آزمون از بازهٔ شروع تا پایان بیشتر است.']]);
        }

        return DB::transaction(function () use ($d, $items, $teacherId) {
            $exam = Exam::create($d + ['teacher_id' => $teacherId, 'status' => 'draft']);
            $this->setQuestions($exam, $items);
            Audit::record('exam.created', $exam, null, ['title' => $exam->title]);

            return $exam;
        });
    }

    public function setQuestions(Exam $exam, array $items): void
    {
        abort_if($exam->status !== 'draft', 422, 'پس از انتشار، سؤالات آزمون قابل تغییر نیست.');
        ExamQuestion::where('exam_id', $exam->id)->delete();
        foreach (array_values($items) as $i => $it) {
            $q = Question::where('subject_id', $exam->subject_id)->find($it['question_id']);
            if (! $q) {
                throw ValidationException::withMessages(['questions' => ['سؤال انتخابی در بانک سؤال این درس نیست.']]);
            }
            ExamQuestion::create(['exam_id' => $exam->id, 'question_id' => $q->id, 'points' => $it['points'] ?? $q->points, 'position' => $i]);
        }
    }

    public function publish(Exam $exam): Exam
    {
        abort_unless(ExamQuestion::where('exam_id', $exam->id)->exists(), 422, 'آزمون بدون سؤال قابل انتشار نیست.');
        if ($exam->status === 'draft') {
            $exam->update(['status' => 'published']);
            $this->notify->send(Recipients::forSection($this->current->id(), $exam->section_id), 'exam.published', "exam-pub:{$exam->id}",
                'آزمون جدید: '.$exam->title, 'شروع: '.$exam->start_at->toDateTimeString(), ['exam_id' => $exam->id]);
        }

        return $exam;
    }

    // ------------------------------------------------------------------ taking
    /** Start, or RESUME the in-progress attempt (after a dropped connection or app restart). */
    public function start(Exam $exam, int $studentId): ExamAttempt
    {
        abort_unless($exam->status === 'published' && $this->access->canViewSection(\App\Models\User::find(Student::find($studentId)->user_id), $exam->section_id), 404);

        return DB::transaction(function () use ($exam, $studentId) {
            Student::whereKey($studentId)->lockForUpdate()->first();   // serialise concurrent starts of the same student
            $open = ExamAttempt::where('exam_id', $exam->id)->where('student_id', $studentId)->where('status', 'in_progress')->first();
            if ($open && $open->deadline_at->gt(now())) {
                return $open;
            }
            $open && $this->submitAttempt($open, true);   // stale attempt: close it first

            if (now()->lt($exam->start_at)) {
                throw ValidationException::withMessages(['exam' => ['آزمون هنوز شروع نشده است.']]);
            }
            if (now()->gt($exam->end_at)) {
                throw ValidationException::withMessages(['exam' => ['زمان آزمون به پایان رسیده است.']]);
            }
            $used = ExamAttempt::where('exam_id', $exam->id)->where('student_id', $studentId)->count();
            if ($used >= $exam->max_attempts) {
                throw ValidationException::withMessages(['exam' => ['تعداد دفعات مجاز شرکت در آزمون تمام شده است.']]);
            }

            $qids = ExamQuestion::where('exam_id', $exam->id)->orderBy('position')->pluck('question_id')->all();
            $exam->shuffle_questions && shuffle($qids);
            $orders = [];
            if ($exam->shuffle_options) {
                foreach (QuestionOption::whereIn('question_id', $qids)->get()->groupBy('question_id') as $qid => $opts) {
                    $ids = $opts->pluck('id')->all();
                    shuffle($ids);
                    $orders[$qid] = $ids;
                }
            }
            $start = now();

            return ExamAttempt::create([
                'exam_id' => $exam->id, 'student_id' => $studentId, 'attempt_no' => $used + 1, 'started_at' => $start,
                'deadline_at' => $start->copy()->addMinutes($exam->duration_minutes)->min($exam->end_at),
                'question_order' => $qids, 'option_orders' => $orders ?: null,
            ]);
        });
    }

    /** Student-facing payload. NEVER contains answer keys, correct flags or rubrics. */
    public function present(ExamAttempt $a, Exam $exam): array
    {
        $qs = Question::whereIn('id', $a->question_order)->get()->keyBy('id');
        $points = ExamQuestion::where('exam_id', $exam->id)->pluck('points', 'question_id');
        $opts = QuestionOption::whereIn('question_id', $a->question_order)->get()->groupBy('question_id');
        $saved = ExamAnswer::where('exam_attempt_id', $a->id)->get()->keyBy('question_id');

        return [
            'attempt' => $a->only(['id', 'attempt_no', 'status', 'started_at', 'deadline_at']),
            'exam' => $exam->only(['id', 'title', 'duration_minutes']),
            'server_time' => now()->toIso8601String(),
            'questions' => collect($a->question_order)->map(function ($qid) use ($qs, $points, $opts, $saved, $a) {
                $q = $qs[$qid];
                $o = $opts[$qid] ?? collect();
                $order = $a->option_orders[$qid] ?? null;
                $o = $order ? collect($order)->map(fn ($id) => $o->firstWhere('id', $id))->filter() : $o->sortBy('position');

                return [
                    'id' => $q->id, 'type' => $q->type, 'body' => $q->body, 'points' => (float) $points[$qid], 'media_file_id' => $q->media_file_id,
                    'multiple' => $q->type === 'mcq' && $o->where('is_correct', true)->count() > 1,
                    'options' => $q->type === 'mcq' ? $o->map(fn ($x) => ['id' => $x->id, 'text' => $x->text])->values() : [],
                    'saved_answer' => $saved[$qid]->answer ?? null,
                ];
            })->values(),
        ];
    }

    /** Autosave. Idempotent; refused after the server deadline. */
    public function saveAnswers(ExamAttempt $a, array $answers): int
    {
        $this->assertWritable($a);
        $allowed = array_flip($a->question_order);
        $n = 0;
        foreach ($answers as $row) {
            if (! isset($allowed[$row['question_id']])) {
                continue;
            }
            ExamAnswer::updateOrCreate(['exam_attempt_id' => $a->id, 'question_id' => $row['question_id']], ['answer' => $row['answer'], 'saved_at' => now()]);
            $n++;
        }

        return $n;
    }

    private function assertWritable(ExamAttempt $a): void
    {
        if ($a->status !== 'in_progress') {
            throw ValidationException::withMessages(['attempt' => ['این تلاش پایان یافته است.']]);
        }
        if (now()->gt($a->deadline_at->copy()->addSeconds(self::GRACE_SECONDS))) {
            $this->submitAttempt($a, true);
            throw ValidationException::withMessages(['attempt' => ['زمان آزمون به پایان رسیده و پاسخ‌ها ثبت نهایی شد.']]);
        }
    }

    public function submitAttempt(ExamAttempt $a, bool $expired = false): ExamAttempt
    {
        return DB::transaction(function () use ($a) {
            $a = ExamAttempt::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($a->status !== 'in_progress') {
                return $a;       // idempotent: double submit / expiry job re-run
            }
            $this->autoGrade($a);
            $a->update(['submitted_at' => now()]);
            $this->finalizeIfComplete($a);

            return $a->refresh();
        });
    }

    // ------------------------------------------------------------------ grading
    public function autoGrade(ExamAttempt $a): void
    {
        $exam = Exam::find($a->exam_id);
        $points = ExamQuestion::where('exam_id', $exam->id)->pluck('points', 'question_id');
        $qs = Question::with('options')->whereIn('id', $a->question_order)->get()->keyBy('id');
        $needsManual = false;
        $auto = 0.0;

        foreach ($a->question_order as $qid) {
            $q = $qs[$qid];
            $ans = ExamAnswer::firstOrCreate(['exam_attempt_id' => $a->id, 'question_id' => $qid]);
            if ($q->type === 'essay') {
                $needsManual = true;
                continue;
            }
            $score = $this->scoreObjective($q, $ans->answer) ? (float) $points[$qid] : 0.0;
            $ans->update(['auto_score' => $score]);
            $auto += $score;
        }
        $a->update(['auto_score' => $auto, 'needs_manual' => $needsManual]);
    }

    private function scoreObjective(Question $q, ?array $answer): bool
    {
        if (! $answer) {
            return false;
        }

        return match ($q->type) {
            'mcq' => (function () use ($q, $answer) {
                $chosen = collect($answer['option_ids'] ?? [])->map(fn ($x) => (int) $x)->sort()->values()->all();
                $correct = $q->options->where('is_correct', true)->pluck('id')->sort()->values()->all();

                return $chosen !== [] && $chosen === $correct;
            })(),
            'tf' => array_key_exists('value', $answer) && (bool) $answer['value'] === (bool) ($q->accepted_answers[0] ?? null),
            'fill', 'short' => in_array(TextNormalizer::normalize($answer['text'] ?? ''), array_map([TextNormalizer::class, 'normalize'], (array) $q->accepted_answers), true),
            default => false,
        };
    }

    /** Teacher scores an essay answer (never above the question's points). */
    public function gradeAnswer(User $by, ExamAnswer $ans, float $score, ?string $feedback): ExamAnswer
    {
        $attempt = ExamAttempt::findOrFail($ans->exam_attempt_id);
        $exam = Exam::findOrFail($attempt->exam_id);
        abort_unless($this->access->canTeach($by, $exam->section_id, $exam->subject_id), 403);
        abort_if($attempt->status === 'in_progress', 422, 'آزمون هنوز در حال انجام است.');
        $max = (float) ExamQuestion::where('exam_id', $exam->id)->where('question_id', $ans->question_id)->value('points');
        if ($score < 0 || $score > $max) {
            throw ValidationException::withMessages(['score' => ["نمره باید بین ۰ و {$max} باشد."]]);
        }
        $ans->update(['manual_score' => $score, 'feedback' => $feedback]);
        $this->finalizeIfComplete($attempt, $by);
        Audit::record('exam.answer_graded', $ans, null, ['score' => $score]);

        return $ans;
    }

    /** When no essay is left ungraded, totals are computed and a DRAFT grade record is written. */
    public function finalizeIfComplete(ExamAttempt $a, ?User $by = null): void
    {
        $essayIds = Question::whereIn('id', $a->question_order)->where('type', 'essay')->pluck('id');
        $pending = ExamAnswer::where('exam_attempt_id', $a->id)->whereIn('question_id', $essayIds)->whereNull('manual_score')->exists()
            || ($essayIds->isNotEmpty() && ExamAnswer::where('exam_attempt_id', $a->id)->whereIn('question_id', $essayIds)->count() < $essayIds->count());
        $manual = (float) ExamAnswer::where('exam_attempt_id', $a->id)->sum('manual_score');
        $a->update(['manual_score' => $manual, 'status' => $pending ? 'submitted' : 'graded', 'total_score' => $pending ? null : (float) $a->auto_score + $manual]);

        if (! $pending) {
            $exam = Exam::find($a->exam_id);
            $term = $exam->term_id ? Term::find($exam->term_id) : Terms::at();
            if ($term) {
                $max = (float) ExamQuestion::where('exam_id', $exam->id)->sum('points');
                $actor = $by ?? User::find(Recipients::teacherUser($exam->teacher_id));
                // Scores are normalised to /20 only at report time; the record keeps raw score and max.
                $this->grades->record($actor, [
                    'student_id' => $a->student_id, 'section_id' => $exam->section_id, 'subject_id' => $exam->subject_id, 'term_id' => $term->id,
                    'kind' => 'exam', 'source_type' => 'exam_attempt', 'source_id' => $a->id, 'title' => $exam->title,
                    'score' => $a->total_score, 'max_score' => $max,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ results & release policy
    public function release(Exam $exam, User $by): Exam
    {
        abort_unless($this->access->canTeach($by, $exam->section_id, $exam->subject_id), 403);
        $exam->update(['results_released_at' => now()]);
        $users = [];
        foreach (ExamAttempt::where('exam_id', $exam->id)->where('status', 'graded')->pluck('student_id')->unique() as $sid) {
            $stu = Student::find($sid);
            $users = array_merge($users, array_filter([$stu->user_id]), Recipients::guardianUsers($sid));
        }
        $this->notify->send($users, 'exam.result', "exam-result:{$exam->id}", 'نتیجهٔ آزمون منتشر شد: '.$exam->title, null, ['exam_id' => $exam->id]);
        Audit::record('exam.results_released', $exam);

        return $exam;
    }

    public function scoreVisible(Exam $e): bool
    {
        return match ($e->show_score) {
            'immediately' => true,
            'after_end' => now()->gte($e->end_at),
            'manual' => $e->results_released_at !== null,
            default => false,
        } || $e->results_released_at !== null;
    }

    /** Correct answers are never shown while the exam window is open for anyone. */
    public function answersVisible(Exam $e): bool
    {
        if ($e->show_answers === 'never' && $e->results_released_at === null) {
            return false;
        }
        if (now()->lt($e->end_at)) {
            return false;
        }

        return match ($e->show_answers) {
            'immediately', 'after_end' => true,
            'manual' => $e->results_released_at !== null,
            default => false,
        };
    }

    public function studentResult(Exam $e, ExamAttempt $a): array
    {
        $out = ['attempt' => $a->only(['id', 'attempt_no', 'status', 'submitted_at']), 'score_visible' => false, 'answers_visible' => false];
        if ($a->status === 'in_progress') {
            return $out;
        }
        if ($this->scoreVisible($e) && $a->status === 'graded') {
            $out['score_visible'] = true;
            $out['total_score'] = $a->total_score;
            $out['max_score'] = (float) ExamQuestion::where('exam_id', $e->id)->sum('points');
        }
        if ($this->answersVisible($e)) {
            $out['answers_visible'] = true;
            $qs = Question::with('options')->whereIn('id', $a->question_order)->get()->keyBy('id');
            $out['review'] = ExamAnswer::where('exam_attempt_id', $a->id)->get()->map(fn ($ans) => [
                'question_id' => $ans->question_id, 'your_answer' => $ans->answer,
                'score' => $ans->manual_score ?? $ans->auto_score, 'feedback' => $ans->feedback,
                'correct' => ['options' => $qs[$ans->question_id]->options->where('is_correct', true)->pluck('id')->values(), 'accepted' => $qs[$ans->question_id]->accepted_answers],
            ])->values();
        }

        return $out;
    }

    /** Class-level analysis for the teacher: score distribution and per-question difficulty. */
    public function analysis(Exam $e): array
    {
        $attempts = ExamAttempt::where('exam_id', $e->id)->whereIn('status', ['submitted', 'graded'])->get();
        $graded = $attempts->where('status', 'graded');
        $max = (float) ExamQuestion::where('exam_id', $e->id)->sum('points');
        $scores = $graded->pluck('total_score')->map(fn ($x) => (float) $x)->values();
        $perQ = [];
        foreach (ExamQuestion::where('exam_id', $e->id)->orderBy('position')->get() as $eq) {
            $answers = ExamAnswer::whereIn('exam_attempt_id', $attempts->pluck('id'))->where('question_id', $eq->question_id)->get();
            $got = $answers->map(fn ($a) => (float) ($a->manual_score ?? $a->auto_score ?? 0));
            $rate = $answers->count() ? round($got->avg() / (float) $eq->points * 100, 1) : null;
            $perQ[] = ['question_id' => $eq->question_id, 'answered' => $answers->count(), 'avg_score' => $got->avg() ? round($got->avg(), 2) : 0,
                'success_rate' => $rate, 'points' => (float) $eq->points, 'hard' => $rate !== null && $rate < 30];
        }

        return [
            'attempts' => $attempts->count(), 'graded' => $graded->count(), 'max_score' => $max,
            'average' => $scores->isEmpty() ? null : round($scores->avg(), 2), 'min' => $scores->min(), 'max' => $scores->max(),
            'distribution' => $max > 0 ? collect(range(0, 4))->map(fn ($i) => ['from' => $i * 20, 'to' => ($i + 1) * 20,
                'count' => $scores->filter(fn ($s) => ($s / $max * 100) >= $i * 20 && (($s / $max * 100) < ($i + 1) * 20 || ($i === 4 && $s / $max * 100 <= 100)))->count()])->all() : [],
            'questions' => $perQ,
        ];
    }

    /** Closes attempts whose server deadline passed (run every minute; safe to repeat). */
    public function expireOverdue(): int
    {
        $n = 0;
        ExamAttempt::where('status', 'in_progress')->where('deadline_at', '<', now()->subSeconds(self::GRACE_SECONDS))->each(function ($a) use (&$n) {
            $this->submitAttempt($a, true);
            $n++;
        });

        return $n;
    }
}

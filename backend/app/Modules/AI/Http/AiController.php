<?php

namespace App\Modules\AI\Http;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiUsageRecord;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Modules\AI\AiException;
use App\Modules\AI\AiService;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiController extends Controller
{
    public function __construct(private AiService $ai, private Access $access) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->ai->status($request->user()));
    }

    /** Student tutor / general Q&A. Optional grounding in approved school materials. */
    public function ask(Request $request): JsonResponse
    {
        $d = $request->validate([
            'message' => ['required', 'string', 'max:4000'], 'use_sources' => ['sometimes', 'boolean'], 'grounded_only' => ['sometimes', 'boolean'],
            'section_id' => ['nullable', ResourceRegistry::existsInSchool('sections')],
        ]);
        $purpose = $this->access->can($request->user(), 'ai.use_teacher') ? 'teacher_chat' : 'student_tutor';

        return response()->json($this->guard(fn () => $this->ai->run($request->user(), $purpose, $d['message'], $d)));
    }

    public function lessonPlan(Request $request): JsonResponse
    {
        $d = $request->validate(['section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'topic' => ['required', 'string', 'max:200'], 'minutes' => ['sometimes', 'integer', 'between:10,180']]);
        abort_unless($this->access->canTeach($request->user(), $d['section_id'], $d['subject_id']), 403);
        [$grade, $subject] = $this->labels($d['section_id'], $d['subject_id']);
        $mins = $d['minutes'] ?? 45;
        $msg = "یک طرح درس {$mins} دقیقه‌ای".' برای درس «'.$subject.'» پایهٔ «'.$grade.'» با موضوع «'.$d['topic'].'» بنویس: هدف‌ها، مواد، مراحل تدریس با زمان‌بندی، فعالیت کلاسی، ارزشیابی و تکلیف.';

        return response()->json($this->guard(fn () => $this->ai->run($request->user(), 'teacher_lesson_plan', $msg, ['use_sources' => true, 'section_id' => $d['section_id'], 'max_tokens' => 2000] + ['minutes' => 45])));
    }

    /** Draft questions; returned for review, never saved automatically. */
    public function generateQuestions(Request $request): JsonResponse
    {
        $d = $request->validate(['subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')], 'topic' => ['required', 'string', 'max:200'],
            'count' => ['required', 'integer', 'between:1,20'], 'difficulty' => ['sometimes', 'integer', 'between:1,5'], 'types' => ['sometimes', 'array'], 'types.*' => ['in:mcq,tf,fill,short,essay'],
            'grade_id' => ['nullable', ResourceRegistry::existsInSchool('grades')]]);
        abort_unless($this->access->isStaff($request->user()) || \App\Models\TeacherAssignment::where('teacher_id', $this->access->teacherId($request->user()) ?? 0)->where('subject_id', $d['subject_id'])->exists(), 403);
        $subject = \App\Models\Subject::find($d['subject_id'])->name;
        $grade = isset($d['grade_id']) ? \App\Models\Grade::find($d['grade_id'])->name : 'نامشخص';
        $types = implode('، ', $d['types'] ?? ['mcq', 'tf', 'short']);
        $msg = "{$d['count']} سؤال برای درس «{$subject}» پایهٔ «{$grade}» دربارهٔ «{$d['topic']}» با دشواری ".($d['difficulty'] ?? 3)."/5 و انواع [{$types}] بساز. "
            .'قالب JSON: {"questions":[{"type":"mcq|tf|fill|short|essay","body":"","options":[{"text":"","is_correct":true}],"accepted_answers":[],"rubric":"","points":1}]}. برای tf در accepted_answers یک مقدار true/false بگذار.';
        $r = $this->guard(fn () => $this->ai->runJson($request->user(), 'teacher_questions', $msg, ['max_tokens' => 3000]));

        return response()->json(['draft' => true, 'questions' => $r['data']['questions'] ?? [], 'note' => 'پیش‌نویس هوش مصنوعی است؛ پس از بازبینی در بانک سؤال ذخیره کنید.']);
    }

    public function rubric(Request $request): JsonResponse
    {
        $d = $request->validate(['question' => ['required', 'string', 'max:4000'], 'max_score' => ['sometimes', 'numeric', 'between:1,100']]);
        $msg = 'برای این سؤال تشریحی معیار تصحیح بساز (JSON: {"criteria":[{"title":"","max":0,"description":""}]}، مجموع امتیازها '.($d['max_score'] ?? 10).'): '.$d['question'];
        $r = $this->guard(fn () => $this->ai->runJson($request->user(), 'teacher_rubric', $msg));

        return response()->json(['draft' => true, 'rubric' => $r['data']]);
    }

    /** Suggestion stored beside the answer; manual_score stays null until the teacher sets it. */
    public function gradeSuggestion(Request $request, int $answerId): JsonResponse
    {
        $ans = ExamAnswer::findOrFail($answerId);
        $attempt = ExamAttempt::findOrFail($ans->exam_attempt_id);
        $exam = Exam::findOrFail($attempt->exam_id);
        abort_unless($this->access->canTeach($request->user(), $exam->section_id, $exam->subject_id), 403);
        $q = Question::findOrFail($ans->question_id);
        abort_unless($q->type === 'essay', 422, 'پیشنهاد نمره فقط برای سؤال تشریحی است.');
        $max = (float) ExamQuestion::where('exam_id', $exam->id)->where('question_id', $q->id)->value('points');
        $msg = "سؤال: {$q->body}\nمعیار تصحیح: ".($q->rubric ?: 'ندارد')."\nحداکثر نمره: {$max}\nپاسخ دانش‌آموز: ".($ans->answer['text'] ?? '')
            ."\nخروجی JSON: {\"suggested_score\":0,\"strengths\":[],\"mistakes\":[],\"rationale\":\"\"}";
        $r = $this->guard(fn () => $this->ai->runJson($request->user(), 'teacher_grading', $msg, ['max_tokens' => 800]));
        $score = isset($r['data']['suggested_score']) ? max(0, min($max, (float) $r['data']['suggested_score'])) : null;
        $sug = ['suggested_score' => $score, 'strengths' => $r['data']['strengths'] ?? [], 'mistakes' => $r['data']['mistakes'] ?? [], 'rationale' => $r['data']['rationale'] ?? '',
            'requires_human_review' => true, 'generated_at' => now()->toIso8601String()];
        $ans->update(['ai_suggestion' => $sug]);

        return response()->json(['suggestion' => $sug, 'applied' => false]);
    }

    /** Weak topics of a student/class from real exam data → remedial practice suggestions for teacher approval. */
    public function remedial(Request $request): JsonResponse
    {
        $d = $request->validate(['section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'student_id' => ['nullable', ResourceRegistry::existsInSchool('students')]]);
        abort_unless($this->access->canTeach($request->user(), $d['section_id'], $d['subject_id']), 403);
        $weak = $this->weakTopics($d['section_id'], $d['subject_id'], $d['student_id'] ?? null);
        if (! $weak) {
            return response()->json(['weak_topics' => [], 'suggestion' => null, 'note' => 'داده‌ٔ کافی برای تحلیل وجود ندارد.']);
        }
        $subject = \App\Models\Subject::find($d['subject_id'])->name;
        $msg = "درس «{$subject}». مباحث ضعیف (درصد موفقیت): ".collect($weak)->map(fn ($w) => "{$w['topic']} ({$w['rate']}%)")->implode('، ')
            .'. برای هر مبحث ۳ تمرین جبرانی با دشواری پلکانی و یک توضیح کوتاه پیشنهاد کن.';
        $r = $this->guard(fn () => $this->ai->run($request->user(), 'teacher_remedial', $msg));

        return response()->json(['weak_topics' => $weak, 'suggestion' => $r['text'], 'requires_teacher_approval' => true]);
    }

    /** Management summary from aggregated numbers only (no student names are sent). */
    public function adminSummary(Request $request): JsonResponse
    {
        $stats = $this->aggregate();
        $msg = 'گزارش خلاصهٔ مدیریتی بنویس و موارد نیازمند توجه را برجسته کن. آمار: '.json_encode($stats, JSON_UNESCAPED_UNICODE);
        $r = $this->guard(fn () => $this->ai->run($request->user(), 'admin_summary', $msg));

        return response()->json(['summary' => $r['text'], 'stats' => $stats]);
    }

    public function usage(Request $request): JsonResponse
    {
        $rows = AiUsageRecord::where('on_date', '>=', now()->subDays(30)->toDateString())->orderBy('on_date')->get();

        return response()->json(['data' => $rows, 'totals' => ['requests' => $rows->sum('requests'), 'input_tokens' => $rows->sum('input_tokens'), 'output_tokens' => $rows->sum('output_tokens'), 'cost_micro' => $rows->sum('cost_micro')],
            'recent_errors' => AiRequest::where('status', 'error')->latest('created_at')->limit(10)->get(['purpose', 'error', 'created_at'])]);
    }

    public function transcribe(Request $request): JsonResponse
    {
        $request->validate(['audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/mpeg,audio/mp4,audio/ogg,audio/wav,audio/x-wav,audio/webm,video/webm']]);
        $f = $request->file('audio');

        return response()->json(['text' => $this->guard(fn () => $this->ai->transcribeFor($request->user(), $f->getRealPath(), (string) $f->getMimeType()))]);
    }

    public function speak(Request $request)
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        $a = $this->guard(fn () => $this->ai->speakFor($request->user(), $d['text']));

        return response($a['bytes'], 200, ['Content-Type' => $a['mime'], 'Cache-Control' => 'no-store']);
    }

    /** Right to erasure for the requester's own AI logs. */
    public function eraseMine(Request $request): JsonResponse
    {
        $n = AiRequest::where('user_id', $request->user()->id)->delete();

        return response()->json(['deleted' => $n]);
    }

    // ------------------------------------------------------------------
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AiException $e) {
            abort(response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status));
        }
    }

    private function labels(int $sectionId, int $subjectId): array
    {
        return [\App\Models\Section::with('grade')->find($sectionId)->grade->name ?? '', \App\Models\Subject::find($subjectId)->name];
    }

    private function weakTopics(int $sectionId, int $subjectId, ?int $studentId): array
    {
        $rows = DB::table('exam_answers as a')->join('exam_attempts as t', 't.id', '=', 'a.exam_attempt_id')->join('exams as e', 'e.id', '=', 't.exam_id')
            ->join('questions as q', 'q.id', '=', 'a.question_id')->join('exam_questions as eq', function ($j) { $j->on('eq.exam_id', '=', 'e.id')->on('eq.question_id', '=', 'q.id'); })
            ->where('e.school_id', $this->access ? app(\App\Modules\Tenancy\CurrentSchool::class)->id() : 0)->where('e.section_id', $sectionId)->where('e.subject_id', $subjectId)
            ->whereIn('t.status', ['submitted', 'graded'])->whereNotNull('q.topic')->when($studentId, fn ($q) => $q->where('t.student_id', $studentId))
            ->selectRaw('q.topic as topic, sum(coalesce(a.manual_score, a.auto_score, 0)) as got, sum(eq.points) as possible, count(*) as n')->groupBy('q.topic')->get();

        return $rows->map(fn ($r) => ['topic' => $r->topic, 'rate' => $r->possible > 0 ? round($r->got / $r->possible * 100) : 0, 'n' => (int) $r->n])
            ->filter(fn ($x) => $x['rate'] < 60 && $x['n'] >= 1)->sortBy('rate')->take(5)->values()->all();
    }

    private function aggregate(): array
    {
        return [
            'attendance_30d' => \App\Models\AttendanceRecord::whereDate('on_date', '>=', now()->subDays(30))->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'sessions_30d' => \App\Models\LessonSession::whereDate('on_date', '>=', now()->subDays(30))->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'overdue_submissions' => \App\Models\Submission::where('is_late', true)->count(),
            'avg_exam_score_pct' => round((float) ExamAttempt::where('status', 'graded')->avg(DB::raw('total_score')), 1),
        ];
    }
}

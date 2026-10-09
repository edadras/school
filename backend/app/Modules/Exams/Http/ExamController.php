<?php

namespace App\Modules\Exams\Http;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\Student;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Exams\ExamService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public function __construct(private ExamService $svc, private Access $access) {}

    // ------------------------------------------------ teacher (exams.manage)
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'term_id' => ['nullable', ResourceRegistry::existsInSchool('terms')], 'title' => ['required', 'string', 'max:150'],
            'start_at' => ['required', 'date', 'after:now'], 'end_at' => ['required', 'date', 'after:start_at'],
            'duration_minutes' => ['required', 'integer', 'between:1,600'], 'max_attempts' => ['sometimes', 'integer', 'between:1,10'],
            'shuffle_questions' => ['sometimes', 'boolean'], 'shuffle_options' => ['sometimes', 'boolean'],
            'show_score' => ['sometimes', Rule::in(['never', 'after_end', 'manual', 'immediately'])], 'show_answers' => ['sometimes', Rule::in(['never', 'after_end', 'manual', 'immediately'])],
            'questions' => ['required', 'array', 'min:1', 'max:200'], 'questions.*.question_id' => ['required', 'integer'], 'questions.*.points' => ['nullable', 'numeric', 'between:0.25,100'],
        ]);
        $items = $d['questions'];
        unset($d['questions']);

        return response()->json(['data' => $this->svc->create($request->user(), $d, $items)], 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $e = $this->ownExam($request, $id);

        return response()->json(['data' => $this->svc->publish($e)]);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->release($this->ownExam($request, $id), $request->user())]);
    }

    public function analysis(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->analysis($this->ownExam($request, $id))]);
    }

    /** Attempts to grade, essay answers included, with the marking guide visible to the teacher only. */
    public function attempts(Request $request, int $id): JsonResponse
    {
        $e = $this->ownExam($request, $id);
        $attempts = ExamAttempt::where('exam_id', $e->id)->whereIn('status', ['submitted', 'graded'])->get();
        $students = Student::whereIn('id', $attempts->pluck('student_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json(['data' => $attempts->map(fn ($a) => $a->only(['id', 'student_id', 'attempt_no', 'status', 'needs_manual', 'auto_score', 'manual_score', 'total_score', 'submitted_at']) + ['student' => $students[$a->student_id] ?? null])]);
    }

    public function attemptDetail(Request $request, int $attemptId): JsonResponse
    {
        $a = ExamAttempt::findOrFail($attemptId);
        $e = $this->ownExam($request, $a->exam_id);
        $qs = Question::with('options')->whereIn('id', $a->question_order)->get()->keyBy('id');

        return response()->json(['attempt' => $a, 'answers' => ExamAnswer::where('exam_attempt_id', $a->id)->get()->map(fn ($x) => [
            'id' => $x->id, 'question' => $qs[$x->question_id], 'answer' => $x->answer, 'auto_score' => $x->auto_score,
            'manual_score' => $x->manual_score, 'feedback' => $x->feedback, 'ai_suggestion' => $x->ai_suggestion,
        ])]);
    }

    public function gradeAnswer(Request $request, int $answerId): JsonResponse
    {
        $d = $request->validate(['score' => ['required', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:5000']]);

        return response()->json(['data' => $this->svc->gradeAnswer($request->user(), ExamAnswer::findOrFail($answerId), (float) $d['score'], $d['feedback'] ?? null)]);
    }

    // ------------------------------------------------ shared list
    public function index(Request $request): JsonResponse
    {
        $q = Exam::query()->orderByDesc('start_at');
        $secs = $this->access->sectionIds($request->user());
        $secs !== null && $q->whereIn('section_id', $secs);
        if (! $this->access->can($request->user(), 'exams.manage')) {
            $q->where('status', '!=', 'draft');
        }
        $request->filled('section_id') && $q->where('section_id', $request->integer('section_id'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    // ------------------------------------------------ student (learn.participate)
    public function start(Request $request, int $id): JsonResponse
    {
        $e = Exam::findOrFail($id);
        $sid = $this->studentId($request, $e);
        $attempt = $this->svc->start($e, $sid);

        return response()->json($this->svc->present($attempt, $e), 201);
    }

    /** Resume after refresh/disconnect: same attempt, saved answers, authoritative deadline. */
    public function attempt(Request $request, int $attemptId): JsonResponse
    {
        $a = $this->ownAttempt($request, $attemptId);

        return response()->json($this->svc->present($a, Exam::findOrFail($a->exam_id)));
    }

    public function saveAnswers(Request $request, int $attemptId): JsonResponse
    {
        $a = $this->ownAttempt($request, $attemptId);
        $d = $request->validate(['answers' => ['required', 'array', 'min:1', 'max:200'], 'answers.*.question_id' => ['required', 'integer'], 'answers.*.answer' => ['required', 'array']]);

        return response()->json(['saved' => $this->svc->saveAnswers($a, $d['answers']), 'server_time' => now()->toIso8601String(), 'deadline_at' => $a->deadline_at]);
    }

    public function submit(Request $request, int $attemptId): JsonResponse
    {
        $a = $this->ownAttempt($request, $attemptId);
        if ($request->has('answers')) {
            $d = $request->validate(['answers' => ['array', 'max:200'], 'answers.*.question_id' => ['required', 'integer'], 'answers.*.answer' => ['required', 'array']]);
            try {
                $this->svc->saveAnswers($a, $d['answers']);
            } catch (\Illuminate\Validation\ValidationException) {
                // deadline passed: the final autosave is dropped, earlier saves still count
            }
        }
        $a = $this->svc->submitAttempt($a->refresh());

        return response()->json($this->svc->studentResult(Exam::findOrFail($a->exam_id), $a));
    }

    public function myResult(Request $request, int $id): JsonResponse
    {
        $e = Exam::findOrFail($id);
        $sid = $request->integer('student_id') ?: $this->access->ownStudentId($request->user());
        abort_unless($sid && in_array($sid, $this->access->studentIds($request->user()), true), 404);
        $a = ExamAttempt::where('exam_id', $e->id)->where('student_id', $sid)->where('status', '!=', 'in_progress')->latest('attempt_no')->first();
        abort_unless($a, 404);

        return response()->json($this->svc->studentResult($e, $a));
    }

    private function ownExam(Request $request, int $id): Exam
    {
        $e = Exam::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $e->section_id, $e->subject_id), 403);

        return $e;
    }

    private function studentId(Request $request, Exam $e): int
    {
        $sid = $this->access->ownStudentId($request->user());
        abort_unless($sid && $this->access->canViewSection($request->user(), $e->section_id), 404);

        return $sid;
    }

    private function ownAttempt(Request $request, int $attemptId): ExamAttempt
    {
        $a = ExamAttempt::findOrFail($attemptId);
        abort_unless($a->student_id === $this->access->ownStudentId($request->user()), 404);

        return $a;
    }
}

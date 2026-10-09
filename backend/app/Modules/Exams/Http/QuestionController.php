<?php

namespace App\Modules\Exams\Http;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\TeacherAssignment;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    public function __construct(private Access $access) {}

    /** Bank is visible to teachers of that subject (and staff). Answer keys are included: this is the authoring API. */
    public function index(Request $request): JsonResponse
    {
        $q = Question::query()->with('options')->orderByDesc('id');
        if (! $this->access->isStaff($request->user())) {
            $subjects = TeacherAssignment::where('teacher_id', $this->access->teacherId($request->user()) ?? 0)->pluck('subject_id');
            $q->whereIn('subject_id', $subjects);
        }
        foreach (['subject_id', 'grade_id', 'difficulty', 'type'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }
        $request->filled('topic') && $q->where('topic', 'like', '%'.$request->string('topic').'%');
        $request->filled('q') && $q->where('body', 'like', '%'.$request->string('q').'%');

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $this->authorizeSubject($request, $d['subject_id']);
        $options = $d['options'] ?? [];
        unset($d['options']);
        $this->assertShape($d, $options);

        $q = DB::transaction(function () use ($request, $d, $options) {
            $q = Question::create($d + ['created_by' => $request->user()->id]);
            foreach (array_values($options) as $i => $o) {
                QuestionOption::create(['question_id' => $q->id, 'text' => $o['text'], 'is_correct' => (bool) ($o['is_correct'] ?? false), 'position' => $i]);
            }

            return $q;
        });

        return response()->json(['data' => $q->load('options')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $q = Question::findOrFail($id);
        $this->authorizeSubject($request, $q->subject_id);
        // Questions already used by a published exam are frozen: editing them would change past results.
        if (DB::table('exam_questions as eq')->join('exams as e', 'e.id', '=', 'eq.exam_id')->where('eq.question_id', $q->id)->where('e.status', '!=', 'draft')->exists()) {
            return response()->json(['message' => 'این سؤال در آزمون منتشرشده استفاده شده و قابل ویرایش نیست.', 'code' => 'question_frozen'], 409);
        }
        $d = $this->validated($request, true);
        $options = $d['options'] ?? null;
        unset($d['options']);
        DB::transaction(function () use ($q, $d, $options) {
            $q->update($d);
            if ($options !== null) {
                $this->assertShape($d + $q->toArray(), $options);
                QuestionOption::where('question_id', $q->id)->delete();
                foreach (array_values($options) as $i => $o) {
                    QuestionOption::create(['question_id' => $q->id, 'text' => $o['text'], 'is_correct' => (bool) ($o['is_correct'] ?? false), 'position' => $i]);
                }
            }
        });
        Audit::record('question.updated', $q);

        return response()->json(['data' => $q->refresh()->load('options')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $q = Question::findOrFail($id);
        $this->authorizeSubject($request, $q->subject_id);
        if (DB::table('exam_questions')->where('question_id', $q->id)->exists()) {
            return response()->json(['message' => 'سؤال در آزمون استفاده شده است.', 'code' => 'delete_blocked'], 409);
        }
        $q->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';

        return $request->validate([
            'subject_id' => [$req, ResourceRegistry::existsInSchool('subjects')], 'grade_id' => ['nullable', ResourceRegistry::existsInSchool('grades')],
            'topic' => ['nullable', 'string', 'max:100'], 'difficulty' => ['sometimes', 'integer', 'between:1,5'],
            'type' => [$req, Rule::in(['mcq', 'tf', 'fill', 'short', 'essay'])], 'body' => [$req, 'string', 'max:10000'],
            'media_file_id' => ['nullable', 'integer'], 'accepted_answers' => ['nullable', 'array', 'max:20'],
            'rubric' => ['nullable', 'string', 'max:10000'], 'points' => ['sometimes', 'numeric', 'between:0.25,100'],
            'options' => ['sometimes', 'array', 'max:8'], 'options.*.text' => ['required_with:options', 'string', 'max:500'], 'options.*.is_correct' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertShape(array $d, array $options): void
    {
        $type = $d['type'] ?? null;
        $err = null;
        if ($type === 'mcq') {
            $err = count($options) < 2 ? 'سؤال چندگزینه‌ای حداقل دو گزینه دارد.' : (collect($options)->where('is_correct', true)->isEmpty() ? 'حداقل یک گزینهٔ درست لازم است.' : null);
        } elseif ($type === 'tf') {
            $err = ! is_bool(($d['accepted_answers'][0] ?? null)) ? 'پاسخ درست/غلط باید مشخص شود.' : null;
        } elseif (in_array($type, ['fill', 'short'], true)) {
            $err = empty($d['accepted_answers']) ? 'حداقل یک پاسخ پذیرفته‌شده لازم است.' : null;
        }
        if ($err) {
            throw ValidationException::withMessages(['question' => [$err]]);
        }
    }

    private function authorizeSubject(Request $request, int $subjectId): void
    {
        abort_unless($this->access->isStaff($request->user()) || TeacherAssignment::where('teacher_id', $this->access->teacherId($request->user()) ?? 0)->where('subject_id', $subjectId)->exists(), 403);
    }
}

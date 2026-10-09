<?php

namespace App\Modules\Learning\Http;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Student;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Learning\AssignmentService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $service, private Access $access) {}

    // ---------- teacher side (content.manage) ----------
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'term_id' => ['nullable', ResourceRegistry::existsInSchool('terms')],
            'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:20000'],
            'publish_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date', 'after:now'],
            'max_score' => ['sometimes', 'numeric', 'between:1,1000'],
            'rubric' => ['nullable', 'array', 'max:20'], 'rubric.*.title' => ['required_with:rubric', 'string', 'max:120'], 'rubric.*.max' => ['required_with:rubric', 'numeric', 'min:0'],
            'answer_types' => ['required', 'array', 'min:1'], 'answer_types.*' => [Rule::in(['text', 'file', 'audio', 'video', 'drawing', 'math'])],
            'allow_draft' => ['sometimes', 'boolean'], 'allow_late' => ['sometimes', 'boolean'], 'allow_resubmit' => ['sometimes', 'boolean'],
            'file_ids' => ['sometimes', 'array', 'max:10'], 'file_ids.*' => ['integer'],
            'publish' => ['sometimes', 'boolean'],
        ]);
        $files = $d['file_ids'] ?? [];
        $publish = (bool) ($d['publish'] ?? false);
        unset($d['file_ids'], $d['publish']);

        $a = $this->service->create($request->user(), $d, $files);
        $publish && $this->service->publish($a);

        return response()->json(['data' => $a->refresh()], 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $a->section_id, $a->subject_id), 403);

        return response()->json(['data' => $this->service->publish($a)]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $a->section_id, $a->subject_id), 403);
        $a->update(['status' => 'closed']);

        return response()->json(['data' => $a]);
    }

    public function submissions(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $a->section_id, $a->subject_id), 403);
        $subs = Submission::where('assignment_id', $a->id)->with('files')->orderBy('student_id')->get();

        // Include students who have not even opened it, so the teacher sees the whole class.
        $enrolled = Student::whereIn('id', \App\Models\Enrollment::where('section_id', $a->section_id)->where('status', 'active')->select('student_id'))
            ->get(['id', 'first_name', 'last_name']);

        return response()->json(['assignment' => $a, 'students' => $enrolled->map(fn ($s) => [
            'student' => $s, 'submission' => $subs->firstWhere('student_id', $s->id),
        ])->values()]);
    }

    public function history(Request $request, int $submissionId): JsonResponse
    {
        $s = Submission::findOrFail($submissionId);
        $a = Assignment::findOrFail($s->assignment_id);
        abort_unless($this->access->canTeach($request->user(), $a->section_id, $a->subject_id), 403);

        return response()->json(['data' => SubmissionVersion::where('submission_id', $s->id)->orderBy('attempt')->get()]);
    }

    public function grade(Request $request, int $submissionId): JsonResponse
    {
        $s = Submission::findOrFail($submissionId);
        $a = Assignment::findOrFail($s->assignment_id);
        $d = $request->validate([
            'outcome' => ['required', Rule::in(['finalize', 'return', 'review'])],
            'score' => ['nullable', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:10000'],
            'rubric_scores' => ['nullable', 'array'], 'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->service->grade($s, $a, $request->user(), $d, $d['outcome'])]);
    }

    // ---------- shared listing ----------
    /** Teachers: own classes' assignments. Students/guardians: published ones in their sections, plus own submission state. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Assignment::query()->orderByDesc('id');
        $sections = $this->access->sectionIds($user);
        $sections !== null && $q->whereIn('section_id', $sections);
        $isTeaching = $this->access->can($user, 'content.manage') || $this->access->isStaff($user);
        if (! $isTeaching) {
            $q->where('status', '!=', 'draft')->where(fn ($w) => $w->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }
        foreach (['section_id', 'subject_id', 'status'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }
        $page = $q->paginate(min((int) $request->integer('per_page', 25), 100));

        if (! $isTeaching) {
            $sid = $request->integer('student_id') ?: ($this->access->ownStudentId($user) ?? ($this->access->studentIds($user)[0] ?? null));
            abort_if($sid && ! in_array($sid, $this->access->studentIds($user), true), 403);
            $subs = $sid ? Submission::where('student_id', $sid)->whereIn('assignment_id', collect($page->items())->pluck('id'))->get()->keyBy('assignment_id') : collect();
            $page->getCollection()->transform(function ($a) use ($subs) {
                $a->setAttribute('my_submission', $subs[$a->id] ?? null);
                $a->setAttribute('state', $subs[$a->id]->status ?? ($a->due_at && $a->due_at->isPast() ? 'overdue' : 'published'));

                return $a;
            });
        }

        return response()->json($page);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        $user = $request->user();
        abort_unless($this->access->canViewSection($user, $a->section_id), 404);
        $teaching = $this->access->canTeach($user, $a->section_id, $a->subject_id);
        abort_if(! $teaching && ! $this->service->isOpenForStudents($a), 404);

        $data = ['assignment' => $a->load([]), 'attachments' => \App\Models\AssignmentAttachment::where('assignment_id', $a->id)->pluck('file_id')];
        if (! $teaching) {
            $sid = $this->access->ownStudentId($user);
            // Opening it as the student marks it "viewed" (guardians only look, they don't change state).
            $data['my_submission'] = $sid ? $this->service->markViewed($a, $sid)->load('files') : null;
        }

        return response()->json($data);
    }

    // ---------- student side (learn.participate) ----------
    public function saveDraft(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        $sid = $this->requireStudent($request, $a);
        $p = $request->validate(['text_answer' => ['nullable', 'string', 'max:50000'], 'drawing' => ['nullable', 'array'], 'math' => ['nullable', 'array']]);

        return response()->json(['data' => $this->service->saveDraft($a, $sid, $p)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $a = Assignment::findOrFail($id);
        $sid = $this->requireStudent($request, $a);
        $p = $request->validate([
            'text_answer' => ['nullable', 'string', 'max:50000'], 'drawing' => ['nullable', 'array'], 'math' => ['nullable', 'array'],
            'file_ids' => ['sometimes', 'array', 'max:10'], 'file_ids.*' => ['integer'],
        ]);
        $files = $p['file_ids'] ?? [];
        unset($p['file_ids']);

        return response()->json(['data' => $this->service->submit($a, $request->user(), $sid, $p, $files)->load('files')]);
    }

    private function requireStudent(Request $request, Assignment $a): int
    {
        $sid = $this->access->ownStudentId($request->user());
        abort_unless($sid && $this->access->canViewSection($request->user(), $a->section_id), 404);

        return $sid;
    }
}

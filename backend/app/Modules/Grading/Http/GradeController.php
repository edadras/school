<?php

namespace App\Modules\Grading\Http;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\GradeRecord;
use App\Models\GradeRecordHistory;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Files\SettingsRepository;
use App\Modules\Grading\GradeCalculator;
use App\Modules\Grading\GradeService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GradeController extends Controller
{
    public function __construct(private GradeService $svc, private Access $access, private GradeCalculator $calc) {}

    /** Teachers see their classes (incl. drafts); staff all; students/guardians approved grades of their own students only. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = GradeRecord::query()->orderByDesc('id');
        if ($this->access->isStaff($user)) {
            // all
        } elseif ($this->access->can($user, 'grades.enter')) {
            $q->whereIn('section_id', $this->access->sectionIds($user) ?? []);
        } else {
            $ids = $this->access->studentIds($user);
            $q->whereIn('student_id', $ids)->where('status', 'approved');
        }
        foreach (['student_id', 'section_id', 'subject_id', 'term_id', 'kind', 'status'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'student_id' => ['required', ResourceRegistry::existsInSchool('students')], 'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')], 'term_id' => ['required', ResourceRegistry::existsInSchool('terms')],
            'kind' => ['required', Rule::in(['classwork', 'oral', 'project', 'final', 'exam', 'assignment'])],
            'title' => ['required', 'string', 'max:150'], 'score' => ['required', 'numeric', 'min:0'], 'max_score' => ['sometimes', 'numeric', 'min:1'],
            'weight' => ['sometimes', 'numeric', 'between:0,100'],
        ]);
        abort_unless($this->access->canTeach($request->user(), $d['section_id'], $d['subject_id']), 403, 'شما معلم این درس در این کلاس نیستید.');
        abort_unless(Enrollment::where('student_id', $d['student_id'])->where('section_id', $d['section_id'])->where('status', 'active')->exists(), 422, 'دانش‌آموز عضو این کلاس نیست.');

        return response()->json(['data' => $this->svc->record($request->user(), $d)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $g = GradeRecord::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $g->section_id, $g->subject_id), 403);
        $d = $request->validate(['score' => ['required', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        // Manual rows have no source: update in place through the same rules.
        return response()->json(['data' => $this->svc->change($request->user(), $g, (float) $d['score'], $d['reason'] ?? null)]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->approve(GradeRecord::findOrFail($id), $request->user())]);
    }

    /** Approve every draft of a class/subject/term at once. */
    public function approveBulk(Request $request): JsonResponse
    {
        $d = $request->validate(['section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'term_id' => ['required', ResourceRegistry::existsInSchool('terms')], 'subject_id' => ['nullable', 'integer']]);
        $n = 0;
        GradeRecord::where('section_id', $d['section_id'])->where('term_id', $d['term_id'])->where('status', 'draft')
            ->when($d['subject_id'] ?? null, fn ($q, $s) => $q->where('subject_id', $s))->each(function ($g) use ($request, &$n) {
                $this->svc->approve($g, $request->user());
                $n++;
            });

        return response()->json(['approved' => $n]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $g = GradeRecord::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $g->section_id, $g->subject_id), 403);

        return response()->json(['data' => GradeRecordHistory::where('grade_record_id', $g->id)->orderBy('id')->get()]);
    }

    /** Term summary of one student computed with the school's formula (approved grades only). */
    public function studentSummary(Request $request, int $studentId): JsonResponse
    {
        abort_unless($this->access->canViewStudent($request->user(), $studentId), 404);
        $termId = $request->integer('term_id');
        abort_unless($termId, 422, 'term_id الزامی است.');
        $rules = $this->calc->rules();
        $bySubject = GradeRecord::where('student_id', $studentId)->where('term_id', $termId)->where('status', 'approved')->get()->groupBy('subject_id');
        $scores = $bySubject->map(fn ($recs) => $this->calc->subjectScore($recs, $rules))->all();

        return response()->json(['subjects' => $scores, 'term' => $this->calc->termResult($scores, $rules), 'rules' => $rules]);
    }

    public function rules(SettingsRepository $s): JsonResponse
    {
        return response()->json(['data' => $this->calc->rules(), 'approval_required' => $this->svc->approvalRequired()]);
    }

    public function updateRules(Request $request, SettingsRepository $s): JsonResponse
    {
        $d = $request->validate([
            'rules.scale_max' => ['sometimes', 'numeric', 'between:1,1000'], 'rules.pass_mark' => ['sometimes', 'numeric', 'min:0'],
            'rules.decimals' => ['sometimes', 'integer', 'between:0,3'], 'rules.rounding' => ['sometimes', Rule::in(['half_up', 'floor', 'ceil'])],
            'rules.kind_weights' => ['sometimes', 'array'], 'rules.kind_weights.*' => ['numeric', 'between:0,100'],
            'rules.min_subject_mark' => ['nullable', 'numeric', 'min:0'], 'rules.max_makeup_subjects' => ['sometimes', 'integer', 'between:0,20'],
            'approval_required' => ['sometimes', 'boolean'],
        ]);
        isset($d['rules']) && $s->set('grading.rules', array_replace((array) $s->get('grading.rules', []), $d['rules']));
        isset($d['approval_required']) && $s->set('grading.approval_required', $d['approval_required']);
        \App\Modules\Audit\Audit::record('grading.rules_changed', null, null, $d);

        return $this->rules($s);
    }
}

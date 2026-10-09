<?php

namespace App\Modules\ReportCards\Http;

use App\Http\Controllers\Controller;
use App\Models\ReportCard;
use App\Models\ReportCardTemplate;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use App\Modules\ReportCards\ReportCardService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportCardController extends Controller
{
    public function __construct(private ReportCardService $svc, private Access $access) {}

    // ---- templates (reportcards.manage)
    public function templates(): JsonResponse
    {
        return response()->json(['data' => ReportCardTemplate::orderByDesc('id')->get()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'header' => ['nullable', 'string', 'max:255'], 'footer' => ['nullable', 'string', 'max:1000'],
            'signatures' => ['required', 'array', 'min:1', 'max:5'], 'signatures.*' => ['string', 'max:60'],
            'options' => ['nullable', 'array'], 'is_default' => ['sometimes', 'boolean'],
        ]);
        $t = ReportCardTemplate::create($d);
        if ($t->is_default) {
            ReportCardTemplate::where('id', '!=', $t->id)->update(['is_default' => false]);
        }

        return response()->json(['data' => $t], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $t = ReportCardTemplate::findOrFail($id);
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'header' => ['nullable', 'string', 'max:255'], 'footer' => ['nullable', 'string', 'max:1000'],
            'signatures' => ['sometimes', 'array', 'min:1', 'max:5'], 'signatures.*' => ['string', 'max:60'], 'options' => ['nullable', 'array'], 'is_default' => ['sometimes', 'boolean']]);
        $t->update($d);
        $t->is_default && ReportCardTemplate::where('id', '!=', $t->id)->update(['is_default' => false]);

        return response()->json(['data' => $t]);
    }

    // ---- generation / issue (reportcards.manage)
    public function generate(Request $request): JsonResponse
    {
        $d = $request->validate([
            'term_id' => ['required', ResourceRegistry::existsInSchool('terms')], 'student_id' => ['nullable', ResourceRegistry::existsInSchool('students')],
            'section_id' => ['nullable', ResourceRegistry::existsInSchool('sections')], 'template_id' => ['nullable', ResourceRegistry::existsInSchool('report_card_templates')],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $term = Term::findOrFail($d['term_id']);
        if (! empty($d['student_id'])) {
            return response()->json(['data' => $this->svc->generate(Student::findOrFail($d['student_id']), $term, $d['template_id'] ?? null, $d['remarks'] ?? null)], 201);
        }
        abort_unless(! empty($d['section_id']), 422, 'student_id یا section_id الزامی است.');

        return response()->json(['generated' => $this->svc->generateForSection(Section::findOrFail($d['section_id']), $term)], 201);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->issue(ReportCard::findOrFail($id), $request->user())]);
    }

    public function issueSection(Request $request): JsonResponse
    {
        $d = $request->validate(['section_id' => ['required', ResourceRegistry::existsInSchool('sections')], 'term_id' => ['required', ResourceRegistry::existsInSchool('terms')]]);
        $ok = $fail = [];
        foreach (ReportCard::where('section_id', $d['section_id'])->where('term_id', $d['term_id'])->where('status', 'draft')->get() as $c) {
            try {
                $this->svc->issue($c, $request->user());
                $ok[] = $c->id;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $fail[] = ['id' => $c->id, 'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['issued' => $ok, 'failed' => $fail]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->svc->revoke(ReportCard::findOrFail($id), $request->user(), $d['reason'])]);
    }

    // ---- reading
    /** Staff: all; students/guardians: ISSUED cards of own/approved students only. */
    public function index(Request $request): JsonResponse
    {
        $q = ReportCard::query()->with('items')->orderByDesc('id');
        if (! $this->access->can($request->user(), 'reportcards.manage')) {
            $q->whereIn('student_id', $this->access->studentIds($request->user()))->where('status', 'issued');
        }
        foreach (['student_id', 'section_id', 'term_id', 'status'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->readable($request, $id)->load('items')]);
    }

    /** Secure view: PDF is generated on demand and streamed to an authorised session only. */
    public function pdf(Request $request, int $id): Response
    {
        $c = $this->readable($request, $id);
        Audit::record('reportcard.pdf_viewed', $c);

        return response($this->svc->pdf($c), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="report-card-'.$c->id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function readable(Request $request, int $id): ReportCard
    {
        $c = ReportCard::findOrFail($id);
        if ($this->access->can($request->user(), 'reportcards.manage')) {
            return $c;
        }
        abort_unless($c->status === 'issued' && in_array($c->student_id, $this->access->studentIds($request->user()), true), 404);

        return $c;
    }
}

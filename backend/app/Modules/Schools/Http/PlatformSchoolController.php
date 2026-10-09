<?php

namespace App\Modules\Schools\Http;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolApprovalRequest;
use App\Modules\Schools\SchoolApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform (super admin) panel API. */
class PlatformSchoolController extends Controller
{
    public function __construct(private SchoolApprovalService $service) {}

    public function requests(Request $request): JsonResponse
    {
        $q = SchoolApprovalRequest::query()->with('school:id,code,name,city,status')->latest('id');
        $request->filled('status') && $q->where('status', $request->string('status'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function schools(Request $request): JsonResponse
    {
        $q = School::query()->with('subscription')->orderBy('id');
        $request->filled('status') && $q->where('status', $request->string('status'));
        $request->filled('q') && $q->where('name', 'like', '%'.$request->string('q').'%');

        return response()->json($q->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function decide(Request $request, SchoolApprovalRequest $approvalRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject,needs_changes'],
            'note' => ['nullable', 'string', 'max:1000', 'required_unless:decision,approve'],
        ]);
        $admin = $request->user();

        $result = match ($data['decision']) {
            'approve' => $this->service->approve($approvalRequest, $admin, $data['note'] ?? null),
            'reject' => $this->service->reject($approvalRequest, $admin, $data['note']),
            'needs_changes' => $this->service->requestChanges($approvalRequest, $admin, $data['note']),
        };

        return response()->json(['request' => $result->only(['id', 'status', 'decision_note'])]);
    }

    public function suspend(Request $request, School $school): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['school' => $this->service->suspend($school, $data['reason'])->only(['id', 'status', 'status_reason'])]);
    }

    public function reactivate(School $school): JsonResponse
    {
        return response()->json(['school' => $this->service->reactivate($school)->only(['id', 'status'])]);
    }

    public function updateSubscription(Request $request, School $school): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', 'max:40'], 'max_students' => ['sometimes', 'integer', 'min:0'],
            'max_teachers' => ['sometimes', 'integer', 'min:0'], 'max_live_sessions' => ['sometimes', 'integer', 'min:0'],
            'max_storage_mb' => ['sometimes', 'integer', 'min:0'],
        ]);
        $sub = $school->subscription ?? $school->subscription()->create([]);
        $old = $sub->only(array_keys($data));
        $sub->update($data);
        \App\Modules\Audit\Audit::record('subscription.updated', $sub, $old, $data, $school->id);

        return response()->json(['subscription' => $sub]);
    }
}

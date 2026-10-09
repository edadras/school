<?php

namespace App\Modules\Schools\Http;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SchoolApprovalRequest;
use App\Modules\Schools\SchoolApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class SchoolRegistrationController extends Controller
{
    public function __construct(private SchoolApprovalService $service) {}

    /** Public, throttled. Creates a PENDING school; nothing is usable until the platform admin approves. */
    public function register(Request $request): JsonResponse
    {
        $open = PlatformSetting::where('key', 'registration_open')->first()?->value;
        if ($open !== null && ! filter_var(is_array($open) ? ($open[0] ?? true) : $open, FILTER_VALIDATE_BOOLEAN)) {
            abort(403, 'ثبت‌نام مدرسهٔ جدید در حال حاضر بسته است.');
        }

        $data = $request->validate([
            'school.name' => ['required', 'string', 'max:255'],
            'school.code' => ['required', 'alpha_dash:ascii', 'min:3', 'max:40', 'unique:schools,code'],
            'school.phone' => ['nullable', 'string', 'max:30'],
            'school.email' => ['nullable', 'email'],
            'school.address' => ['nullable', 'string', 'max:500'],
            'school.city' => ['nullable', 'string', 'max:100'],
            'school.timezone' => ['nullable', 'timezone:all'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'unique:users,email'],
            'owner.phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'owner.password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $req = $this->service->register($data['school'], $data['owner']);

        return response()->json([
            'message' => 'درخواست ثبت مدرسه ارسال شد و پس از بررسی مدیر کل فعال می‌شود.',
            'request_id' => $req->id, 'status' => $req->status,
        ], 201);
    }

    /** School owner follows up their own request status / resubmits after "needs_changes". */
    public function myRequest(Request $request): JsonResponse
    {
        $req = SchoolApprovalRequest::where('submitted_by', $request->user()->id)->latest('id')->first();
        abort_unless($req, 404);

        return response()->json(['request' => $req->only(['id', 'status', 'decision_note', 'decided_at', 'school_id'])]);
    }

    public function resubmit(Request $request): JsonResponse
    {
        $changes = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:100'],
        ]);
        $req = SchoolApprovalRequest::where('submitted_by', $request->user()->id)->where('status', 'needs_changes')->latest('id')->firstOrFail();

        return response()->json(['request' => $this->service->resubmit($req, $changes)->only(['id', 'status'])]);
    }
}

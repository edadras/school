<?php

namespace App\Modules\Schools;

use App\Models\Role;
use App\Models\School;
use App\Models\SchoolApprovalRequest;
use App\Models\SchoolSubscription;
use App\Models\SchoolUserMembership;
use App\Models\User;
use App\Modules\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lifecycle: pending -> (approved | rejected | needs_changes -> pending) ; active <-> suspended. */
class SchoolApprovalService
{
    /** Public self-registration: creates the owner user, school (pending) and the approval request. */
    public function register(array $school, array $owner, array $documents = []): SchoolApprovalRequest
    {
        return DB::transaction(function () use ($school, $owner, $documents) {
            $user = User::create($owner);
            $model = School::create($school);
            $model->forceFill(['status' => 'pending', 'owner_user_id' => $user->id])->save();

            SchoolUserMembership::create([
                'school_id' => $model->id, 'user_id' => $user->id,
                'role_id' => Role::where('key', 'school_admin')->value('id'), 'status' => 'active',
            ]);

            return SchoolApprovalRequest::create([
                'school_id' => $model->id, 'submitted_by' => $user->id,
                'payload' => $school, 'documents' => $documents, 'status' => 'pending',
            ]);
        });
    }

    public function resubmit(SchoolApprovalRequest $request, array $schoolChanges): SchoolApprovalRequest
    {
        $this->assertStatus($request, 'needs_changes');

        return DB::transaction(function () use ($request, $schoolChanges) {
            $school = $request->school;
            $school->update($schoolChanges);
            $school->forceFill(['status' => 'pending', 'status_reason' => null])->save();
            $request->update(['status' => 'superseded']);

            return SchoolApprovalRequest::create([
                'school_id' => $school->id, 'submitted_by' => $request->submitted_by,
                'payload' => $school->only(['name', 'phone', 'email', 'address', 'city']),
                'documents' => $request->documents, 'status' => 'pending',
            ]);
        });
    }

    public function approve(SchoolApprovalRequest $request, User $admin, ?string $note = null): SchoolApprovalRequest
    {
        return $this->decide($request, $admin, 'approved', 'active', $note, function (School $school) {
            $school->forceFill(['activated_at' => now()])->save();
            // Default plan; limits are editable by the platform admin afterwards.
            SchoolSubscription::firstOrCreate(['school_id' => $school->id], ['plan' => 'free', 'status' => 'active']);
        });
    }

    public function reject(SchoolApprovalRequest $request, User $admin, string $note): SchoolApprovalRequest
    {
        return $this->decide($request, $admin, 'rejected', 'rejected', $note);
    }

    public function requestChanges(SchoolApprovalRequest $request, User $admin, string $note): SchoolApprovalRequest
    {
        return $this->decide($request, $admin, 'needs_changes', 'needs_changes', $note);
    }

    public function suspend(School $school, string $reason): School
    {
        if ($school->status !== 'active') {
            throw ValidationException::withMessages(['school' => ['فقط مدرسه فعال قابل تعلیق است.']]);
        }
        $school->forceFill(['status' => 'suspended', 'status_reason' => $reason])->save();
        // Cut off every live API token of that school's members is unnecessary: ResolveSchool
        // rejects non-active schools on every request.
        Audit::record('school.suspended', $school, ['status' => 'active'], ['status' => 'suspended', 'reason' => $reason], $school->id);

        return $school;
    }

    public function reactivate(School $school): School
    {
        if ($school->status !== 'suspended') {
            throw ValidationException::withMessages(['school' => ['مدرسه در وضعیت تعلیق نیست.']]);
        }
        $school->forceFill(['status' => 'active', 'status_reason' => null])->save();
        Audit::record('school.reactivated', $school, ['status' => 'suspended'], ['status' => 'active'], $school->id);

        return $school;
    }

    private function decide(SchoolApprovalRequest $request, User $admin, string $requestStatus, string $schoolStatus, ?string $note, ?callable $after = null): SchoolApprovalRequest
    {
        return DB::transaction(function () use ($request, $admin, $requestStatus, $schoolStatus, $note, $after) {
            // Lock to make double-clicks / concurrent admins safe.
            $request = SchoolApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($request, 'pending');

            $school = School::findOrFail($request->school_id);
            $old = $school->status;

            $request->update(['status' => $requestStatus, 'decision_note' => $note, 'decided_by' => $admin->id, 'decided_at' => now()]);
            $school->forceFill(['status' => $schoolStatus, 'status_reason' => $note])->save();
            $after && $after($school);

            Audit::record("school.{$requestStatus}", $school, ['status' => $old], ['status' => $schoolStatus, 'note' => $note], $school->id);

            return $request->refresh();
        });
    }

    private function assertStatus(SchoolApprovalRequest $request, string $expected): void
    {
        if ($request->status !== $expected) {
            throw ValidationException::withMessages(['request' => ['این درخواست در وضعیت قابل‌پردازش نیست.']]);
        }
    }
}

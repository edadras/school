<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\SchoolApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class SchoolApprovalTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function registerPayload(string $code = 'hope'): array
    {
        return [
            'school' => ['name' => 'مدرسه امید', 'code' => $code, 'city' => 'تهران'],
            'owner' => ['name' => 'مدیر', 'email' => "$code@example.test", 'password' => 'Str0ng-Password1', 'password_confirmation' => 'Str0ng-Password1'],
        ];
    }

    public function test_registration_creates_pending_school_that_cannot_operate_until_approved(): void
    {
        $this->postJson('/api/v1/schools/register', $this->registerPayload())->assertCreated();

        $school = School::where('code', 'hope')->firstOrFail();
        $this->assertSame('pending', $school->status);

        $owner = $school->owner_user_id ? \App\Models\User::find($school->owner_user_id) : null;
        $this->as($owner, $school)->getJson('/api/v1/academics/grades')->assertForbidden();
    }

    public function test_super_admin_approves_and_school_becomes_usable(): void
    {
        $this->postJson('/api/v1/schools/register', $this->registerPayload())->assertCreated();
        $school = School::where('code', 'hope')->first();
        $owner = \App\Models\User::find($school->owner_user_id);
        $req = SchoolApprovalRequest::first();

        $this->as($this->makePlatformAdmin())
            ->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('request.status', 'approved');

        $this->assertSame('active', $school->refresh()->status);
        $this->assertNotNull($school->subscription);
        $this->assertTrue(AuditLog::where('action', 'school.approved')->where('school_id', $school->id)->exists());

        $this->as($owner, $school)->postJson('/api/v1/academics/grades', ['name' => 'اول'])->assertCreated();
    }

    public function test_decision_cannot_be_applied_twice(): void
    {
        $this->postJson('/api/v1/schools/register', $this->registerPayload())->assertCreated();
        $req = SchoolApprovalRequest::first();
        $admin = $this->makePlatformAdmin();

        $this->as($admin)->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'reject', 'note' => 'مدارک ناقص'])->assertOk();
        $this->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame('rejected', School::first()->status);
    }

    public function test_needs_changes_then_resubmit_returns_to_pending(): void
    {
        $this->postJson('/api/v1/schools/register', $this->registerPayload())->assertCreated();
        $req = SchoolApprovalRequest::first();
        $owner = \App\Models\User::find(School::first()->owner_user_id);

        $this->as($this->makePlatformAdmin())->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'needs_changes', 'note' => 'آدرس'])->assertOk();
        $this->as($owner)->postJson('/api/v1/schools/my-request/resubmit', ['address' => 'خیابان ولیعصر'])->assertOk()->assertJsonPath('request.status', 'pending');

        $this->assertSame('pending', School::first()->status);
        $this->assertSame('superseded', $req->refresh()->status);
    }

    public function test_reject_requires_note_and_only_super_admin_can_decide(): void
    {
        $this->postJson('/api/v1/schools/register', $this->registerPayload())->assertCreated();
        $req = SchoolApprovalRequest::first();
        $owner = \App\Models\User::find(School::first()->owner_user_id);

        $this->as($owner)->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->as($this->makePlatformAdmin())->postJson("/api/v1/platform/approval-requests/{$req->id}/decision", ['decision' => 'reject'])->assertStatus(422);
    }

    public function test_suspended_school_is_locked_out_immediately(): void
    {
        $school = $this->makeSchool('s1');
        $admin = $this->makeMember($school, 'school_admin');
        $this->as($admin, $school)->getJson('/api/v1/academics/grades')->assertOk();

        $this->as($this->makePlatformAdmin())->postJson("/api/v1/platform/schools/{$school->id}/suspend", ['reason' => 'عدم پرداخت'])->assertOk();

        $this->as($admin, $school)->getJson('/api/v1/academics/grades')->assertForbidden();
    }

    public function test_cannot_self_assign_platform_role_at_registration(): void
    {
        $payload = $this->registerPayload();
        $payload['owner']['platform_role'] = 'super_admin';
        $this->postJson('/api/v1/schools/register', $payload)->assertCreated();

        $this->assertNull(\App\Models\User::where('email', 'hope@example.test')->value('platform_role'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Student;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_admin_cannot_read_update_or_delete_another_schools_records(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $adminA = $this->makeMember($a, 'school_admin');
        $gradeB = $this->inSchool($b, fn () => Grade::create(['name' => 'B-grade']));

        $this->as($adminA, $a);
        $this->getJson("/api/v1/academics/grades/{$gradeB->id}")->assertNotFound();
        $this->patchJson("/api/v1/academics/grades/{$gradeB->id}", ['name' => 'hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/academics/grades/{$gradeB->id}")->assertNotFound();
        $this->getJson('/api/v1/academics/grades')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('B-grade', Grade::withoutGlobalScopes()->find($gradeB->id)->name);
    }

    public function test_x_school_id_header_for_a_foreign_school_is_rejected(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $adminA = $this->makeMember($a, 'school_admin');

        $this->as($adminA, $b)->getJson('/api/v1/academics/grades')->assertForbidden()->assertJsonPath('code', 'school_not_resolved');
    }

    public function test_foreign_ids_in_payload_fail_validation(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $adminA = $this->makeMember($a, 'school_admin');
        $fxB = $this->academicFixture($b);

        $this->as($adminA, $a)->postJson('/api/v1/academics/sections', [
            'grade_id' => $fxB['grade']->id, 'academic_year_id' => $fxB['year']->id, 'name' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['grade_id', 'academic_year_id']);
    }

    public function test_client_supplied_school_id_is_ignored_on_create(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $adminA = $this->makeMember($a, 'school_admin');

        $id = $this->as($adminA, $a)->postJson('/api/v1/academics/grades', ['name' => 'Z', 'school_id' => $b->id])->assertCreated()->json('data.id');

        $this->assertSame($a->id, Grade::withoutGlobalScopes()->find($id)->school_id);
    }

    public function test_tenant_models_fail_closed_without_context(): void
    {
        $a = $this->makeSchool('a');
        $this->inSchool($a, fn () => Grade::create(['name' => 'G']));

        $this->assertSame(0, Grade::count(), 'no tenant => no rows');
        $this->expectException(\LogicException::class);
        Grade::create(['name' => 'orphan']);
    }

    public function test_context_does_not_leak_after_run(): void
    {
        $a = $this->makeSchool('a');
        $this->inSchool($a, fn () => null);
        $this->assertNull(app(CurrentSchool::class)->id());
    }

    public function test_user_in_two_schools_must_choose_and_only_sees_chosen(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $u = $this->makeMember($a, 'school_admin');
        \App\Models\SchoolUserMembership::create(['school_id' => $b->id, 'user_id' => $u->id, 'role_id' => \App\Models\Role::where('key', 'teacher')->value('id')]);
        $this->inSchool($a, fn () => Grade::create(['name' => 'only-a']));

        $this->as($u)->getJson('/api/v1/academics/grades')->assertForbidden(); // ambiguous => explicit choice required
        $this->as($u, $a)->getJson('/api/v1/academics/grades')->assertOk()->assertJsonCount(1, 'data');
        // Role is per school: in B the user is only a teacher and may not create grades... 
        $this->as($u, $b)->postJson('/api/v1/academics/grades', ['name' => 'x'])->assertForbidden();
    }

    public function test_notification_feed_is_per_user_and_school(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $ua = $this->makeMember($a, 'teacher');
        $ub = $this->makeMember($b, 'teacher');
        $this->inSchool($a, fn () => app(\App\Modules\Notifications\NotificationService::class)->send([$ua->id], 't', 'k1', 'private to A'));

        $this->as($ub, $b)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->as($ua, $a)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_student_limit_from_subscription_is_enforced(): void
    {
        $a = $this->makeSchool('a');
        $admin = $this->makeMember($a, 'school_admin');
        \App\Models\SchoolSubscription::where('school_id', $a->id)->update(['max_students' => 1]);

        $this->as($admin, $a)->postJson('/api/v1/academics/students', ['first_name' => 'a', 'last_name' => 'b', 'student_code' => '1'])->assertCreated();
        $this->postJson('/api/v1/academics/students', ['first_name' => 'c', 'last_name' => 'd', 'student_code' => '2'])->assertStatus(422)->assertJsonValidationErrors('limit');
        $this->assertSame(1, Student::withoutGlobalScopes()->count());
    }
}

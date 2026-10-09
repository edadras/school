<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class AcademicsAndRbacTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_admin_builds_school_structure_end_to_end(): void
    {
        $s = $this->makeSchool('a');
        $admin = $this->makeMember($s, 'school_admin');
        $this->as($admin, $s);

        $year = $this->postJson('/api/v1/academics/academic-years', ['title' => '1405', 'starts_on' => '2026-09-23', 'ends_on' => '2027-06-20', 'is_current' => true])->assertCreated()->json('data.id');
        $grade = $this->postJson('/api/v1/academics/grades', ['name' => 'هفتم', 'level' => 7])->assertCreated()->json('data.id');
        $section = $this->postJson('/api/v1/academics/sections', ['grade_id' => $grade, 'academic_year_id' => $year, 'name' => 'الف', 'capacity' => 1])->assertCreated()->json('data.id');
        $subject = $this->postJson('/api/v1/academics/subjects', ['name' => 'ریاضی', 'grade_id' => $grade])->assertCreated()->json('data.id');
        $teacher = $this->postJson('/api/v1/teachers', ['name' => 'معلم', 'email' => 't@example.test', 'password' => 'Str0ng-Password1'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/teacher-assignments', ['teacher_id' => $teacher, 'section_id' => $section, 'subject_id' => $subject])->assertCreated();
        // a second teacher for the same section+subject is a conflict
        $t2 = $this->postJson('/api/v1/teachers', ['name' => 'معلم۲', 'email' => 't2@example.test', 'password' => 'Str0ng-Password1'])->json('data.id');
        $this->postJson('/api/v1/teacher-assignments', ['teacher_id' => $t2, 'section_id' => $section, 'subject_id' => $subject])->assertStatus(422);

        $s1 = $this->postJson('/api/v1/academics/students', ['first_name' => 'علی', 'last_name' => 'رضایی', 'student_code' => '1001'])->json('data.id');
        $s2 = $this->postJson('/api/v1/academics/students', ['first_name' => 'سارا', 'last_name' => 'احمدی', 'student_code' => '1002'])->json('data.id');
        $this->postJson('/api/v1/enrollments', ['student_id' => $s1, 'section_id' => $section])->assertCreated();
        $this->postJson('/api/v1/enrollments', ['student_id' => $s1, 'section_id' => $section])->assertOk(); // idempotent
        $this->postJson('/api/v1/enrollments', ['student_id' => $s2, 'section_id' => $section])->assertStatus(422)->assertJsonValidationErrors('section_id'); // capacity 1

        $this->assertTrue(AuditLog::where('action', 'enrollment.created')->exists());
    }

    public function test_duplicate_student_code_only_conflicts_within_the_same_school(): void
    {
        $a = $this->makeSchool('a');
        $b = $this->makeSchool('b');
        $this->as($this->makeMember($a, 'school_admin'), $a)->postJson('/api/v1/academics/students', ['first_name' => 'x', 'last_name' => 'y', 'student_code' => '77'])->assertCreated();
        $this->postJson('/api/v1/academics/students', ['first_name' => 'x', 'last_name' => 'y', 'student_code' => '77'])->assertStatus(422);
        $this->as($this->makeMember($b, 'school_admin'), $b)->postJson('/api/v1/academics/students', ['first_name' => 'x', 'last_name' => 'y', 'student_code' => '77'])->assertCreated();
    }

    public function test_role_matrix_is_enforced_server_side(): void
    {
        $s = $this->makeSchool('a');
        $teacher = $this->makeMember($s, 'teacher');
        $student = $this->makeMember($s, 'student');
        $deputy = $this->makeMember($s, 'deputy');

        $this->as($teacher, $s)->getJson('/api/v1/academics/grades')->assertOk();                       // academics.view
        $this->postJson('/api/v1/academics/grades', ['name' => 'x'])->assertForbidden();                  // no academics.manage
        $this->postJson('/api/v1/teachers', ['name' => 'x', 'email' => 'x@e.test', 'password' => 'Str0ng-Password1'])->assertForbidden();
        $this->as($student, $s)->getJson('/api/v1/academics/grades')->assertForbidden();
        $this->getJson('/api/v1/timetables')->assertForbidden();
        $this->as($deputy, $s)->postJson('/api/v1/academics/grades', ['name' => 'ok'])->assertCreated();
        $this->getJson('/api/v1/platform/schools')->assertForbidden();                                     // never platform
    }

    public function test_delete_is_blocked_when_dependents_exist_and_history_is_kept(): void
    {
        $s = $this->makeSchool('a');
        $admin = $this->makeMember($s, 'school_admin');
        $fx = $this->academicFixture($s);

        $this->as($admin, $s)->deleteJson("/api/v1/academics/grades/{$fx['grade']->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/academics/academic-years/{$fx['year']->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/teacher-assignments/".\App\Models\TeacherAssignment::withoutGlobalScopes()->value('id'))->assertNoContent();
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::create(['action' => 'x']);
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'y']);
    }

    public function test_guardian_sees_only_approved_children(): void
    {
        $s = $this->makeSchool('a');
        $admin = $this->makeMember($s, 'school_admin');
        $this->as($admin, $s);
        $mine = $this->postJson('/api/v1/academics/students', ['first_name' => 'فرزند', 'last_name' => 'من', 'student_code' => '1'])->json('data.id');
        $other = $this->postJson('/api/v1/academics/students', ['first_name' => 'دیگری', 'last_name' => 'x', 'student_code' => '2'])->json('data.id');
        $this->postJson("/api/v1/students/$mine/guardians", ['name' => 'والد', 'email' => 'p@example.test', 'password' => 'Str0ng-Password1'])->assertCreated();

        $parent = \App\Models\User::where('email', 'p@example.test')->first();
        $this->as($parent, $s)->getJson('/api/v1/me/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/v1/academics/students/$other")->assertForbidden(); // knowing the id grants nothing

        // revocation removes access
        $guardianId = \App\Models\Guardian::withoutGlobalScopes()->value('id');
        $this->as($admin, $s)->deleteJson("/api/v1/students/$mine/guardians/$guardianId")->assertOk();
        $this->as($parent, $s)->getJson('/api/v1/me/children')->assertJsonCount(0, 'data');
        $this->assertSame(2, Student::withoutGlobalScopes()->count());
    }
}

<?php

namespace Tests\Support;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SchoolUserMembership;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Modules\Auth\RbacSync;
use App\Modules\Tenancy\CurrentSchool;
use Laravel\Sanctum\Sanctum;

trait MakesSchools
{
    protected function seedRbac(): void
    {
        RbacSync::run();
    }

    protected function makeSchool(string $code, string $status = 'active'): School
    {
        $s = School::create(['code' => $code, 'name' => "مدرسه $code", 'timezone' => 'Asia/Tehran']);
        $s->forceFill(['status' => $status])->save();
        SchoolSubscription::create(['school_id' => $s->id, 'plan' => 'free', 'max_students' => 50, 'max_teachers' => 10]);

        return $s;
    }

    protected function makeMember(School $school, string $roleKey, ?string $email = null): User
    {
        $u = User::create(['name' => "$roleKey user", 'email' => $email ?? uniqid($roleKey).'@example.test', 'password' => 'Password-12345']);
        SchoolUserMembership::create(['school_id' => $school->id, 'user_id' => $u->id, 'role_id' => Role::where('key', $roleKey)->value('id')]);

        return $u;
    }

    protected function makePlatformAdmin(): User
    {
        $u = User::create(['name' => 'root', 'email' => 'root@example.test', 'password' => 'Password-12345']);
        $u->forceFill(['platform_role' => 'super_admin'])->save();

        return $u;
    }

    /** Run model setup code inside a school's tenant context. */
    protected function inSchool(School $school, callable $fn): mixed
    {
        return app(CurrentSchool::class)->run($school, $fn);
    }

    protected function as(User $user, ?School $school = null): static
    {
        Sanctum::actingAs($user);
        $this->withHeaders($school ? ['X-School-Id' => (string) $school->id] : []);

        return $this;
    }

    /** Year + grade + section + subject + teacher(+assignment) fixture. */
    protected function academicFixture(School $school): array
    {
        return $this->inSchool($school, function () use ($school) {
            $year = AcademicYear::create(['title' => '1405', 'starts_on' => '2026-09-23', 'ends_on' => '2027-06-20', 'is_current' => true]);
            $grade = Grade::create(['name' => 'هفتم', 'level' => 7]);
            $section = Section::create(['grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'الف', 'capacity' => 2]);
            $math = Subject::create(['name' => 'ریاضی', 'grade_id' => $grade->id]);
            $sci = Subject::create(['name' => 'علوم', 'grade_id' => $grade->id]);
            $tUser = $this->makeMember($school, 'teacher');
            $teacher = Teacher::create(['user_id' => $tUser->id]);
            TeacherAssignment::create(['teacher_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $math->id]);
            TeacherAssignment::create(['teacher_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $sci->id]);

            return compact('year', 'grade', 'section', 'math', 'sci', 'teacher', 'tUser');
        });
    }
}

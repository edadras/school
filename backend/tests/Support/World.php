<?php

namespace Tests\Support;

use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Models\User;

/** A realistic school: admin, deputy, teacher (math+science), two students with accounts, a guardian, an outsider class. */
trait World
{
    use MakesSchools;

    protected $school;
    protected User $admin;
    protected User $teacher;
    protected User $deputy;
    protected array $fx;
    protected Term $term;
    protected Student $s1;
    protected Student $s2;
    protected User $u1;
    protected User $u2;
    protected User $guardian;
    protected Student $outsider;
    protected User $outsiderUser;
    protected Section $section2;

    protected function buildWorld(string $code = 'w'): void
    {
        $this->seedRbac();
        $this->school = $this->makeSchool($code);
        $this->admin = $this->makeMember($this->school, 'school_admin');
        $this->deputy = $this->makeMember($this->school, 'deputy');
        $this->fx = $this->academicFixture($this->school);
        $this->teacher = $this->fx['tUser'];

        $this->inSchool($this->school, function () {
            $this->term = Term::create(['academic_year_id' => $this->fx['year']->id, 'title' => 'ترم اول', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(3)->toDateString()]);
            $this->section2 = Section::create(['grade_id' => $this->fx['grade']->id, 'academic_year_id' => $this->fx['year']->id, 'name' => 'ب', 'capacity' => 30]);
        });
        $this->u1 = $this->makeMember($this->school, 'student');
        $this->u2 = $this->makeMember($this->school, 'student');
        $this->outsiderUser = $this->makeMember($this->school, 'student');
        $this->guardian = $this->makeMember($this->school, 'guardian');
        $this->inSchool($this->school, function () {
            $mk = fn (User $u, string $code, $section) => tap(Student::create(['user_id' => $u->id, 'first_name' => 'نام'.$code, 'last_name' => 'خانوادگی'.$code, 'student_code' => $code]),
                fn ($s) => Enrollment::create(['student_id' => $s->id, 'section_id' => $section->id, 'academic_year_id' => $this->fx['year']->id]));
            $this->s1 = $mk($this->u1, '1001', $this->fx['section']);
            $this->s2 = $mk($this->u2, '1002', $this->fx['section']);
            $this->outsider = $mk($this->outsiderUser, '2001', $this->section2);
            $g = Guardian::create(['user_id' => $this->guardian->id]);
            StudentGuardian::create(['student_id' => $this->s1->id, 'guardian_id' => $g->id, 'status' => 'approved']);
        });
    }

    protected function as_(User $u): static
    {
        return $this->as($u, $this->school);
    }
}

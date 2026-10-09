<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class StudentFileTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    private $school;
    private $admin;
    private array $fx;
    private $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->school = $this->makeSchool('a');
        $this->admin = $this->makeMember($this->school, 'school_admin');
        $this->fx = $this->academicFixture($this->school);
        $this->inSchool($this->school, function () {
            $this->student = Student::create(['first_name' => 'علی', 'last_name' => 'کریمی', 'student_code' => 'S1']);
            Enrollment::create(['student_id' => $this->student->id, 'section_id' => $this->fx['section']->id, 'academic_year_id' => $this->fx['year']->id]);
        });
    }

    private function nextYearSection(int $capacity = 5): Section
    {
        return $this->inSchool($this->school, function () use ($capacity) {
            $y = AcademicYear::create(['title' => 'y'.uniqid(), 'starts_on' => '2027-09-23', 'ends_on' => '2028-06-20']);

            return Section::create(['grade_id' => $this->fx['grade']->id, 'academic_year_id' => $y->id, 'name' => 'الف', 'capacity' => $capacity]);
        });
    }

    public function test_promotion_moves_the_class_to_the_next_year_and_keeps_history(): void
    {
        $to = $this->nextYearSection();
        $r = $this->as($this->admin, $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $to->id])->assertOk();
        $this->assertSame(1, $r->json('promoted'));
        $this->inSchool($this->school, function () use ($to) {
            $this->assertSame(['promoted', 'active'], Enrollment::where('student_id', $this->student->id)->orderBy('id')->pluck('status')->all());
            $this->assertSame($to->id, Enrollment::where('student_id', $this->student->id)->where('status', 'active')->value('section_id'));
        });
        // running it again must not duplicate anything
        $again = $this->as($this->admin, $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $to->id])->assertOk();
        $this->assertSame(0, $again->json('promoted'));
        $dossier = $this->as($this->admin, $this->school)->getJson("/api/v1/students/{$this->student->id}/dossier")->assertOk();
        $this->assertCount(2, $dossier->json('data.enrollments'));
    }

    public function test_only_passed_skips_students_without_a_passing_report_card_and_respects_capacity(): void
    {
        $to = $this->nextYearSection();
        $r = $this->as($this->admin, $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $to->id, 'only_passed' => true])->assertOk();
        $this->assertSame(0, $r->json('promoted'));
        $this->assertStringContainsString('کارنامه', $r->json('skipped.0.reason'));

        $full = $this->nextYearSection(0);
        $r = $this->as($this->admin, $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $full->id])->assertOk();
        $this->assertSame(0, $r->json('promoted'));
        $this->assertStringContainsString('ظرفیت', $r->json('skipped.0.reason'));
    }

    public function test_promotion_into_the_same_year_is_rejected_and_teachers_cannot_promote(): void
    {
        $this->as($this->admin, $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $this->fx['section']->id])->assertStatus(422);
        $this->as($this->fx['tUser'], $this->school)->postJson('/api/v1/students/promote', ['from_section_id' => $this->fx['section']->id, 'to_section_id' => $this->nextYearSection()->id])->assertForbidden();
    }

    public function test_dossier_lists_only_approved_grades_per_term_with_subject_names(): void
    {
        $this->inSchool($this->school, function () {
            $term = \App\Models\Term::create(['academic_year_id' => $this->fx['year']->id, 'title' => 'نیم‌سال اول', 'starts_on' => '2026-09-23', 'ends_on' => '2027-02-10']);
            $base = ['student_id' => $this->student->id, 'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'term_id' => $term->id, 'kind' => 'exam', 'title' => 't', 'max_score' => 20];
            \App\Models\GradeRecord::create($base + ['score' => 18, 'status' => 'approved']);
            \App\Models\GradeRecord::create($base + ['score' => 2, 'status' => 'draft', 'kind' => 'oral']);
        });
        $d = $this->as($this->admin, $this->school)->getJson("/api/v1/students/{$this->student->id}/dossier")->assertOk()->json('data');
        $this->assertSame('نیم‌سال اول', $d['terms'][0]['term']);
        $this->assertSame('1405', $d['terms'][0]['academic_year']);
        $this->assertArrayHasKey('ریاضی', $d['terms'][0]['subjects']);
        $this->assertEquals(18, $d['terms'][0]['subjects']['ریاضی']);          // the draft 2/20 is not counted
    }

    public function test_dossier_is_visible_to_staff_but_not_to_an_unrelated_guardian(): void
    {
        $this->as($this->admin, $this->school)->getJson("/api/v1/students/{$this->student->id}/dossier")->assertOk()->assertJsonPath('data.student.student_code', 'S1');
        $stranger = $this->makeMember($this->school, 'guardian');
        $this->as($stranger, $this->school)->getJson("/api/v1/students/{$this->student->id}/dossier")->assertNotFound();
    }

    public function test_anonymize_requires_the_code_removes_identity_and_keeps_the_record(): void
    {
        $url = "/api/v1/students/{$this->student->id}/anonymize";
        $this->as($this->admin, $this->school)->postJson($url, ['confirm_code' => 'wrong'])->assertStatus(422);
        $this->inSchool($this->school, fn () => StudentNote::create(['student_id' => $this->student->id, 'author_id' => $this->admin->id, 'kind' => 'discipline', 'title' => 'x', 'body' => 'private text']));
        $this->as($this->admin, $this->school)->postJson($url, ['confirm_code' => 'S1'])->assertOk();
        $this->inSchool($this->school, function () {
            $s = Student::find($this->student->id);
            $this->assertSame('حذف‌شده', $s->first_name);
            $this->assertSame('archived', $s->status);
            $this->assertNull(StudentNote::first()->body);
            $this->assertSame(1, Enrollment::where('student_id', $s->id)->count());   // academic record stays, without identity
        });
    }
}

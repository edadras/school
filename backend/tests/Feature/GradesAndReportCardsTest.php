<?php

namespace Tests\Feature;

use App\Models\GradeRecord;
use App\Models\GradeRecordHistory;
use App\Models\Subject;
use App\Modules\Grading\GradeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

class GradesAndReportCardsTest extends TestCase
{
    use RefreshDatabase, World;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function grade(array $over = []): int
    {
        return $this->as_($this->teacher)->postJson('/api/v1/grades', $over + [
            'student_id' => $this->s1->id, 'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'term_id' => $this->term->id,
            'kind' => 'classwork', 'title' => 'فعالیت کلاسی', 'score' => 15, 'max_score' => 20,
        ])->assertCreated()->json('data.id');
    }

    public function test_calculator_formula_rounding_coefficients_and_result(): void
    {
        $calc = app(GradeCalculator::class);
        $rules = $calc->rules();
        $recs = [
            new GradeRecord(['kind' => 'exam', 'score' => 16, 'max_score' => 20, 'weight' => 1]),          // 16 ×3
            new GradeRecord(['kind' => 'classwork', 'score' => 10, 'max_score' => 10, 'weight' => 1]),      // 20 ×1
        ];
        $this->assertSame(17.0, $calc->subjectScore($recs, $rules));                                          // (16*3+20*1)/4
        $this->assertSame(10.0, $calc->subjectScore([new GradeRecord(['kind' => 'exam', 'score' => 50, 'max_score' => 100, 'weight' => 1])], $rules)); // scales to /20
        $this->assertNull($calc->subjectScore([], $rules));

        $this->assertSame(12.35, $calc->round(12.345, $rules));                                                // half-up
        $this->assertSame(12.34, $calc->round(12.349, array_replace($rules, ['rounding' => 'floor'])), 'floor rule');
    }

    public function test_term_result_uses_coefficients_pass_mark_and_makeup_rule(): void
    {
        $calc = app(GradeCalculator::class);
        $m = $this->fx['math']->id;
        $s = $this->fx['sci']->id;
        $this->inSchool($this->school, fn () => Subject::whereKey($m)->update(['coefficient' => 3]));
        $this->inSchool($this->school, function () use ($calc, $m, $s) {
            $r = $calc->termResult([$m => 18, $s => 8], $calc->rules());
            $this->assertSame(15.5, $r['average']);                  // (18*3 + 8*1)/4
            $this->assertSame(['makeup', 1], [$r['result'], $r['failed_subjects']]);
            $this->assertSame('passed', $calc->termResult([$m => 18, $s => 12], $calc->rules())['result']);
            $this->assertSame('failed', $calc->termResult([$m => 8, $s => 9], $calc->rules())['result']);
        });
    }

    public function test_school_can_change_the_formula_and_it_is_audited(): void
    {
        $this->as_($this->teacher)->putJson('/api/v1/grading/rules', ['rules' => ['pass_mark' => 12]])->assertForbidden();
        $this->as_($this->deputy)->putJson('/api/v1/grading/rules', ['rules' => ['pass_mark' => 12, 'rounding' => 'floor', 'kind_weights' => ['exam' => 5]]])->assertOk()->assertJsonPath('data.pass_mark', 12)->assertJsonPath('data.kind_weights.exam', 5);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grading.rules_changed']);
        $this->putJson('/api/v1/grading/rules', ['rules' => ['rounding' => 'banana']])->assertStatus(422);
    }

    public function test_teacher_enters_drafts_students_see_only_approved_and_changes_are_audited(): void
    {
        $id = $this->grade();
        $this->as_($this->u1)->getJson('/api/v1/grades')->assertJsonCount(0, 'data');
        $this->as_($this->teacher)->postJson("/api/v1/grades/$id/approve")->assertForbidden();               // teachers can't approve
        $this->as_($this->teacher)->patchJson("/api/v1/grades/$id", ['score' => 16])->assertOk();              // draft: free edit
        $this->as_($this->deputy)->postJson("/api/v1/grades/$id/approve")->assertOk();
        $this->as_($this->u1)->getJson('/api/v1/grades')->assertJsonCount(1, 'data')->assertJsonPath('data.0.score', 16);
        $this->as_($this->guardian)->getJson('/api/v1/grades')->assertJsonCount(1, 'data');
        $this->as_($this->u2)->getJson('/api/v1/grades')->assertJsonCount(0, 'data');

        // approved grade: teacher cannot silently change it
        $this->as_($this->teacher)->patchJson("/api/v1/grades/$id", ['score' => 20, 'reason' => 'اشتباه'])->assertStatus(422);
        $this->as_($this->deputy)->patchJson("/api/v1/grades/$id", ['score' => 17])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/grades/$id", ['score' => 17, 'reason' => 'خطای محاسبه'])->assertOk();

        $hist = GradeRecordHistory::withoutGlobalScopes()->where('grade_record_id', $id)->orderBy('id')->get();
        $this->assertSame([15.0, 16.0, 16.0, 17.0], $hist->map(fn ($h) => $h->new_score === null ? null : (float) $h->new_score)->all());
        $this->assertSame('خطای محاسبه', $hist->last()->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grade.changed_after_approval']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grade.approved']);
        $this->as_($this->teacher)->getJson("/api/v1/grades/$id/history")->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_score_bounds_enrollment_and_teaching_scope(): void
    {
        $base = ['student_id' => $this->s1->id, 'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'term_id' => $this->term->id, 'kind' => 'oral', 'title' => 'x'];
        $this->as_($this->teacher)->postJson('/api/v1/grades', $base + ['score' => 25, 'max_score' => 20])->assertStatus(422);
        $this->postJson('/api/v1/grades', ['student_id' => $this->outsider->id] + $base + ['score' => 10])->assertStatus(422);                 // not in this class
        $this->postJson('/api/v1/grades', ['section_id' => $this->section2->id, 'student_id' => $this->outsider->id] + $base + ['score' => 10])->assertForbidden();
    }

    public function test_approval_not_required_when_school_turns_it_off(): void
    {
        $this->as_($this->deputy)->putJson('/api/v1/grading/rules', ['approval_required' => false])->assertOk();
        $this->grade();
        $this->assertSame('approved', GradeRecord::withoutGlobalScopes()->first()->status);
    }

    public function test_report_card_end_to_end_pdf_and_visibility(): void
    {
        $this->grade(['kind' => 'exam', 'title' => 'میان‌ترم', 'score' => 18]);
        $this->grade(['kind' => 'classwork', 'score' => 10, 'max_score' => 10]);
        $this->grade(['subject_id' => $this->fx['sci']->id, 'kind' => 'exam', 'title' => 'علوم', 'score' => 14]);

        $tpl = $this->as_($this->deputy)->postJson('/api/v1/report-card-templates', ['name' => 'پیش‌فرض', 'header' => 'کارنامه پایان ترم', 'signatures' => ['مدیر', 'معلم'], 'is_default' => true])->assertCreated()->json('data.id');
        $card = $this->postJson('/api/v1/report-cards/generate', ['term_id' => $this->term->id, 'student_id' => $this->s1->id, 'remarks' => 'تلاش‌گر'])->assertCreated()->json('data');
        $this->assertNull($card['average']);                                                                   // nothing approved yet → no invented numbers
        $this->postJson("/api/v1/report-cards/{$card['id']}/issue")->assertStatus(422);                        // unapproved grades block issuing

        $this->postJson('/api/v1/grades/approve-bulk', ['section_id' => $this->fx['section']->id, 'term_id' => $this->term->id])->assertOk()->assertJsonPath('approved', 3);
        $card = $this->postJson('/api/v1/report-cards/generate', ['term_id' => $this->term->id, 'student_id' => $this->s1->id])->assertCreated()->json('data');
        $this->assertSame(['template' => $tpl, 'average' => 16.25, 'result' => 'passed'], ['template' => $card['template_id'], 'average' => (float) $card['average'], 'result' => $card['result']]);   // math (18*3+20)/4=18.5, sci 14 → 16.25
    }

    public function test_issue_pdf_notify_and_parent_visibility(): void
    {
        $this->grade(['kind' => 'exam', 'title' => 'میان‌ترم', 'score' => 18]);
        $this->as_($this->deputy)->postJson('/api/v1/grades/approve-bulk', ['section_id' => $this->fx['section']->id, 'term_id' => $this->term->id]);
        $id = $this->postJson('/api/v1/report-cards/generate', ['term_id' => $this->term->id, 'student_id' => $this->s1->id])->json('data.id');

        // drafts are invisible to families
        $this->as_($this->guardian)->getJson('/api/v1/report-cards')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/report-cards/$id")->assertNotFound();
        $this->as_($this->deputy)->postJson("/api/v1/report-cards/$id/issue")->assertOk()->assertJsonPath('data.status', 'issued');
        $this->postJson("/api/v1/report-cards/$id/issue")->assertOk();                                           // idempotent
        $this->assertSame(1, \App\Models\OutboxNotification::withoutGlobalScopes()->where('type', 'reportcard.issued')->where('user_id', $this->guardian->id)->count());
        $this->postJson('/api/v1/report-cards/generate', ['term_id' => $this->term->id, 'student_id' => $this->s1->id])->assertStatus(422);     // issued cards are immutable

        $this->as_($this->guardian)->getJson('/api/v1/report-cards')->assertJsonCount(1, 'data');
        $this->as_($this->u1)->getJson("/api/v1/report-cards/$id")->assertOk();
        $this->as_($this->u2)->getJson("/api/v1/report-cards/$id")->assertNotFound();                            // classmate cannot see it
        $this->as_($this->outsiderUser)->getJson("/api/v1/report-cards/$id/pdf")->assertNotFound();

        $pdf = $this->as_($this->guardian)->get("/api/v1/report-cards/$id/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
        getenv('DUMP_PDF') && file_put_contents(getenv('DUMP_PDF'), $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'reportcard.pdf_viewed']);

        $this->as_($this->deputy)->postJson("/api/v1/report-cards/$id/revoke", ['reason' => 'خطای ثبت'])->assertOk();
        $this->as_($this->guardian)->getJson('/api/v1/report-cards')->assertJsonCount(0, 'data');
    }

    public function test_student_summary_is_scoped_and_uses_only_approved_grades(): void
    {
        $this->grade(['score' => 18]);
        $this->as_($this->u1)->getJson("/api/v1/grades/students/{$this->s1->id}/summary?term_id={$this->term->id}")->assertOk()->assertJsonPath('term.average', null);   // draft ignored
        $this->as_($this->deputy)->postJson('/api/v1/grades/approve-bulk', ['section_id' => $this->fx['section']->id, 'term_id' => $this->term->id]);
        $this->as_($this->u1)->getJson("/api/v1/grades/students/{$this->s1->id}/summary?term_id={$this->term->id}")->assertJsonPath('term.average', 18);
        $this->getJson("/api/v1/grades/students/{$this->s2->id}/summary?term_id={$this->term->id}")->assertNotFound();
    }
}

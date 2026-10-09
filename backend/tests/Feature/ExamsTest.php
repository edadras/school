<?php

namespace Tests\Feature;

use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\GradeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

class ExamsTest extends TestCase
{
    use RefreshDatabase, World;

    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo(Carbon::parse('2026-10-10 06:00:00', 'UTC'));
    }

    private function bank(): void
    {
        $t = $this->as_($this->teacher);
        $sub = $this->fx['math']->id;
        $this->q['mcq'] = $t->postJson('/api/v1/questions', ['subject_id' => $sub, 'type' => 'mcq', 'body' => '۲+۲؟', 'topic' => 'جمع', 'points' => 2, 'options' => [['text' => '۳'], ['text' => '۴', 'is_correct' => true], ['text' => '۵']]])->assertCreated()->json('data.id');
        $this->q['tf'] = $t->postJson('/api/v1/questions', ['subject_id' => $sub, 'type' => 'tf', 'body' => 'عدد ۷ اول است', 'topic' => 'اعداد', 'points' => 1, 'accepted_answers' => [true]])->assertCreated()->json('data.id');
        $this->q['fill'] = $t->postJson('/api/v1/questions', ['subject_id' => $sub, 'type' => 'fill', 'body' => 'پایتخت ایران ____', 'topic' => 'جغرافیا', 'points' => 1, 'accepted_answers' => ['تهران']])->assertCreated()->json('data.id');
        $this->q['essay'] = $t->postJson('/api/v1/questions', ['subject_id' => $sub, 'type' => 'essay', 'body' => 'قضیهٔ فیثاغورس را شرح دهید', 'topic' => 'هندسه', 'points' => 4, 'rubric' => 'بیان قضیه ۲، مثال ۲'])->assertCreated()->json('data.id');
    }

    private function exam(array $over = []): int
    {
        $this->bank();

        $id = $this->as_($this->teacher)->postJson('/api/v1/exams', $over + [
            'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'میان‌ترم', 'start_at' => now()->addMinutes(10)->toIso8601String(),
            'end_at' => now()->addMinutes(100)->toIso8601String(), 'duration_minutes' => 30, 'max_attempts' => 2,
            'questions' => array_map(fn ($k) => ['question_id' => $this->q[$k]], ['mcq', 'tf', 'fill', 'essay']),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/exams/$id/publish")->assertOk();

        return $id;
    }

    public function test_question_validation_per_type(): void
    {
        $t = $this->as_($this->teacher);
        $s = $this->fx['math']->id;
        $t->postJson('/api/v1/questions', ['subject_id' => $s, 'type' => 'mcq', 'body' => 'x', 'options' => [['text' => 'a'], ['text' => 'b']]])->assertStatus(422);       // no correct option
        $t->postJson('/api/v1/questions', ['subject_id' => $s, 'type' => 'mcq', 'body' => 'x', 'options' => [['text' => 'a', 'is_correct' => true]]])->assertStatus(422); // too few
        $t->postJson('/api/v1/questions', ['subject_id' => $s, 'type' => 'fill', 'body' => 'x'])->assertStatus(422);
        $t->postJson('/api/v1/questions', ['subject_id' => $s, 'type' => 'tf', 'body' => 'x'])->assertStatus(422);
        $this->as_($this->u1)->postJson('/api/v1/questions', ['subject_id' => $s, 'type' => 'essay', 'body' => 'x'])->assertForbidden();
    }

    public function test_students_never_receive_answer_keys(): void
    {
        $id = $this->exam();
        $this->travelTo(now()->addMinutes(11));
        $payload = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->assertCreated()->json();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach (['is_correct', 'accepted_answers', 'rubric', 'بیان قضیه ۲'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        $this->assertCount(4, $payload['questions']);
        $this->assertSame(3, count($payload['questions'][0]['options'] ?? []) + count($payload['questions'][1]['options'] ?? []) + count($payload['questions'][2]['options'] ?? []) + count($payload['questions'][3]['options'] ?? []));
    }

    public function test_cannot_start_before_after_or_beyond_attempt_limit_and_outsiders_are_blocked(): void
    {
        $id = $this->exam();
        $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->assertStatus(422);                       // not started yet
        $this->as_($this->outsiderUser)->postJson("/api/v1/exams/$id/start")->assertNotFound();
        $this->travelTo(now()->addMinutes(11));
        $a1 = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $this->postJson("/api/v1/exam-attempts/$a1/submit")->assertOk();
        $a2 = $this->postJson("/api/v1/exams/$id/start")->assertCreated()->json('attempt.id');
        $this->assertNotSame($a1, $a2);
        $this->postJson("/api/v1/exam-attempts/$a2/submit")->assertOk();
        $this->postJson("/api/v1/exams/$id/start")->assertStatus(422);                                        // max_attempts = 2
        $this->travelTo(now()->addHours(3));
        $this->as_($this->u2)->postJson("/api/v1/exams/$id/start")->assertStatus(422);                        // window closed
    }

    public function test_autosave_resume_and_auto_grading(): void
    {
        $id = $this->exam();
        $this->travelTo(now()->addMinutes(11));
        $att = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->assertCreated()->json();
        $aid = $att['attempt']['id'];
        $opts = collect($att['questions'])->firstWhere('id', $this->q['mcq'])['options'];
        $correct = collect($opts)->firstWhere('text', '۴')['id'];

        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['mcq'], 'answer' => ['option_ids' => [$correct]]], ['question_id' => $this->q['tf'], 'answer' => ['value' => true]]]])
            ->assertOk()->assertJsonPath('saved', 2);

        // connection drops; app restarts and resumes the SAME attempt with saved answers and the server deadline
        $this->travelTo(now()->addMinutes(5));
        $again = $this->postJson("/api/v1/exams/$id/start")->assertCreated()->json();
        $this->assertSame($aid, $again['attempt']['id']);
        $this->assertSame(['option_ids' => [$correct]], collect($again['questions'])->firstWhere('id', $this->q['mcq'])['saved_answer']);
        $this->getJson("/api/v1/exam-attempts/$aid")->assertOk()->assertJsonPath('attempt.id', $aid);
        $this->as_($this->u2)->getJson("/api/v1/exam-attempts/$aid")->assertNotFound();                          // not someone else's attempt

        $res = $this->as_($this->u1)->postJson("/api/v1/exam-attempts/$aid/submit", ['answers' => [['question_id' => $this->q['fill'], 'answer' => ['text' => ' تهران ']], ['question_id' => $this->q['essay'], 'answer' => ['text' => 'در مثلث قائم‌الزاویه ...']]]])->assertOk()->json();
        $attempt = ExamAttempt::withoutGlobalScopes()->find($aid);
        $this->assertSame(4.0, (float) $attempt->auto_score);                  // 2 + 1 + 1, essay pending
        $this->assertSame('submitted', $attempt->status);
        $this->assertTrue($attempt->needs_manual);
        $this->assertFalse($res['score_visible']);
        $this->postJson("/api/v1/exam-attempts/$aid/submit")->assertOk();      // double submit is a no-op
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['tf'], 'answer' => ['value' => false]]]])->assertStatus(422);  // locked after submit
    }

    public function test_server_deadline_is_authoritative_and_expiry_job_closes_abandoned_attempts(): void
    {
        $id = $this->exam();
        $this->travelTo(now()->addMinutes(11));
        $aid = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['tf'], 'answer' => ['value' => true]]]])->assertOk();

        $this->travelTo(now()->addMinutes(31));                                  // duration 30 min passed
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['fill'], 'answer' => ['text' => 'تهران']]]])->assertStatus(422);
        $attempt = ExamAttempt::withoutGlobalScopes()->find($aid);
        $this->assertSame('submitted', $attempt->status);                         // auto-submitted at the deadline
        $this->assertSame(1.0, (float) $attempt->auto_score);                     // only the answer saved in time counted

        // a student who closed the browser: the scheduled job finishes it
        $aid2 = $this->as_($this->u2)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $this->travelTo(now()->addMinutes(40));
        $this->artisan('exams:expire-attempts')->assertSuccessful();
        $this->assertSame('submitted', ExamAttempt::withoutGlobalScopes()->find($aid2)->status);
        $this->artisan('exams:expire-attempts')->assertSuccessful();               // idempotent
    }

    public function test_manual_grading_flow_creates_draft_grade_and_respects_release_policy(): void
    {
        $id = $this->exam(['show_score' => 'manual', 'show_answers' => 'manual']);
        $this->travelTo(now()->addMinutes(11));
        $aid = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['tf'], 'answer' => ['value' => true]], ['question_id' => $this->q['essay'], 'answer' => ['text' => 'پاسخ تشریحی']]]]);
        $this->postJson("/api/v1/exam-attempts/$aid/submit")->assertOk();

        // teacher grades the essay
        $detail = $this->as_($this->teacher)->getJson("/api/v1/exam-attempts/$aid/detail")->assertOk()->json();
        $essay = collect($detail['answers'])->firstWhere('question.type', 'essay');
        $this->putJson("/api/v1/exam-answers/{$essay['id']}/grade", ['score' => 5])->assertStatus(422);                    // max 4
        $this->putJson("/api/v1/exam-answers/{$essay['id']}/grade", ['score' => 3.5, 'feedback' => 'خوب'])->assertOk();
        $this->as_($this->u1)->putJson("/api/v1/exam-answers/{$essay['id']}/grade", ['score' => 4])->assertForbidden();
        $a = ExamAttempt::withoutGlobalScopes()->find($aid);
        $this->assertSame(['graded', 4.5], [$a->status, (float) $a->total_score]);                                           // tf 1 + essay 3.5
        $g = GradeRecord::withoutGlobalScopes()->first();
        $this->assertSame(['draft', 4.5, 8.0], [$g->status, (float) $g->score, (float) $g->max_score]);

        // not visible until released
        $this->as_($this->u1)->getJson("/api/v1/exams/$id/my-result")->assertOk()->assertJsonPath('score_visible', false)->assertJsonPath('answers_visible', false);
        $this->as_($this->teacher)->postJson("/api/v1/exams/$id/release")->assertOk();
        $this->as_($this->u1)->getJson("/api/v1/exams/$id/my-result")->assertJsonPath('score_visible', true)->assertJsonPath('total_score', 4.5)
            ->assertJsonPath('answers_visible', false);                                                                       // exam window still open: no answers
        $this->travelTo(now()->addHours(3));
        $this->getJson("/api/v1/exams/$id/my-result")->assertJsonPath('answers_visible', true)->assertJsonStructure(['review' => [['correct', 'feedback']]]);
        $this->as_($this->u2)->getJson("/api/v1/exams/$id/my-result")->assertNotFound();
    }

    public function test_correct_answers_are_never_shown_while_window_is_open_even_with_immediate_policy(): void
    {
        $id = $this->exam(['show_score' => 'immediately', 'show_answers' => 'immediately']);
        $this->travelTo(now()->addMinutes(11));
        $aid = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $r = $this->postJson("/api/v1/exam-attempts/$aid/submit")->assertOk()->json();
        $this->assertFalse($r['answers_visible']);
        $this->assertArrayNotHasKey('review', $r);
    }

    public function test_auto_graded_only_exam_shows_score_immediately_and_normalised_text_matches(): void
    {
        $this->bank();
        $id = $this->as_($this->teacher)->postJson('/api/v1/exams', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'کوتاه', 'start_at' => now()->addMinutes(1)->toIso8601String(),
            'end_at' => now()->addMinutes(60)->toIso8601String(), 'duration_minutes' => 20, 'show_score' => 'immediately', 'questions' => [['question_id' => $this->q['fill']], ['question_id' => $this->q['mcq']]]])->json('data.id');
        $this->postJson("/api/v1/exams/$id/publish");
        $this->travelTo(now()->addMinutes(2));
        $aid = $this->as_($this->u1)->postJson("/api/v1/exams/$id/start")->json('attempt.id');
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $this->q['fill'], 'answer' => ['text' => 'تهران']]]]);
        $this->postJson("/api/v1/exam-attempts/$aid/submit")->assertOk()->assertJsonPath('score_visible', true)->assertJsonPath('total_score', 1)->assertJsonPath('max_score', 3);
        // Arabic yeh/kaf variants & digits normalise
        $this->assertSame(\App\Support\TextNormalizer::normalize('عليرضا ۱۲'), \App\Support\TextNormalizer::normalize('علیرضا 12'));
    }

    public function test_shuffling_and_class_analysis(): void
    {
        $id = $this->exam(['shuffle_questions' => true, 'shuffle_options' => true]);
        $this->travelTo(now()->addMinutes(11));
        $orders = [];
        foreach ([$this->u1, $this->u2] as $u) {
            $p = $this->as_($u)->postJson("/api/v1/exams/$id/start")->json();
            $orders[] = array_column($p['questions'], 'id');
            $this->postJson("/api/v1/exam-attempts/{$p['attempt']['id']}/submit")->assertOk();
        }
        $this->assertSame(sort($orders[0]), sort($orders[1]));                                       // same questions (order may differ)
        $an = $this->as_($this->teacher)->getJson("/api/v1/exams/$id/analysis")->assertOk()->json('data');
        $this->assertSame(2, $an['attempts']);
        $this->assertCount(4, $an['questions']);
        $this->assertTrue(collect($an['questions'])->every(fn ($q) => $q['hard'] === true));        // nobody answered → 0% → flagged hard
        $this->as_($this->u1)->getJson("/api/v1/exams/$id/analysis")->assertForbidden();
    }

    public function test_published_exam_questions_are_frozen(): void
    {
        $id = $this->exam();
        $this->as_($this->teacher)->patchJson("/api/v1/questions/{$this->q['mcq']}", ['body' => 'تغییر'])->assertStatus(409)->assertJsonPath('code', 'question_frozen');
        $this->deleteJson("/api/v1/questions/{$this->q['mcq']}")->assertStatus(409);
    }

    public function test_exam_window_must_fit_duration_and_teacher_scope(): void
    {
        $this->bank();
        $base = ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'x', 'questions' => [['question_id' => $this->q['tf']]]];
        $this->as_($this->teacher)->postJson('/api/v1/exams', $base + ['start_at' => now()->addMinutes(5)->toIso8601String(), 'end_at' => now()->addMinutes(20)->toIso8601String(), 'duration_minutes' => 60])->assertStatus(422);
        $this->postJson('/api/v1/exams', ['section_id' => $this->section2->id] + $base + ['start_at' => now()->addMinutes(5)->toIso8601String(), 'end_at' => now()->addMinutes(80)->toIso8601String(), 'duration_minutes' => 30])->assertForbidden();
    }
}

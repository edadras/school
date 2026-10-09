<?php

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\AiUsageRecord;
use App\Models\ExamAnswer;
use App\Modules\AI\AiProvider;
use App\Modules\AI\Anonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\World;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase, World;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAiProvider::$sent = [];
        FakeAiProvider::$fail = false;
        FakeAiProvider::$configured = true;
        FakeAiProvider::$reply = 'پاسخ آزمایشی';
        $this->app->bind(AiProvider::class, FakeAiProvider::class);
        $this->buildWorld();
    }

    private function enable(): void
    {
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['ai.enabled' => true]])->assertOk();
    }

    public function test_disabled_by_default_and_when_unconfigured_it_says_so(): void
    {
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'سلام'])->assertForbidden()->assertJsonPath('code', 'ai_disabled_by_school');
        $this->enable();
        FakeAiProvider::$configured = false;
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'سلام'])->assertStatus(503)->assertJsonPath('code', 'ai_unconfigured');
        $this->getJson('/api/v1/ai/status')->assertJsonPath('configured', false);
        $this->assertSame([], FakeAiProvider::$sent);
    }

    public function test_default_null_provider_is_unconfigured(): void
    {
        $this->app->bind(AiProvider::class, fn () => new \App\Modules\AI\Providers\NullAiProvider);
        $this->enable();
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertStatus(503)->assertJsonPath('code', 'ai_unconfigured');
    }

    public function test_personal_data_never_reaches_the_model(): void
    {
        $this->enable();
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => "سلام من نام1001 هستم، ایمیلم a@b.com و تلفنم 09123456789 و کد ملی ۱۲۳۴۵۶۷۸۹۰. این مسئله را حل کن"])->assertOk()->assertJsonPath('text', 'پاسخ آزمایشی');
        $sent = json_encode(FakeAiProvider::$sent, JSON_UNESCAPED_UNICODE);
        foreach (['a@b.com', '09123456789', '1234567890', 'نام1001'] as $pii) {
            $this->assertStringNotContainsString($pii, $sent);
        }
        $this->assertStringContainsString('[ایمیل]', $sent);
        $this->assertStringContainsString('هفتم', $sent);                         // level is sent, identity is not
        // only a hash of the prompt is stored
        $row = AiRequest::withoutGlobalScopes()->first();
        $this->assertSame(64, strlen($row->prompt_hash));
        $this->assertSame('ok', $row->status);
    }

    public function test_student_tutor_is_told_not_to_give_homework_answers_and_open_assignments_are_listed(): void
    {
        $this->enable();
        $this->as_($this->teacher)->postJson('/api/v1/assignments', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'تمرین معادله', 'answer_types' => ['text'], 'publish' => true]);
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'کمک'])->assertOk();
        $sys = FakeAiProvider::$sent[0]['system'];
        $this->assertStringContainsString('پاسخ نهایی تکلیف', $sys);
        $this->assertStringContainsString('تمرین معادله', $sys);
    }

    public function test_role_grade_level_and_school_switches(): void
    {
        $this->enable();
        $this->as_($this->guardian)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertForbidden();                    // no ai permission
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['ai.student_min_grade_level' => 9]]);
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertForbidden()->assertJsonPath('code', 'ai_grade_restricted');   // grade 7 < 9
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['ai.student_min_grade_level' => 1, 'ai.student_enabled' => false]]);
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertForbidden()->assertJsonPath('code', 'ai_disabled_for_students');
        $this->as_($this->teacher)->postJson('/api/v1/ai/ask', ['message' => 'یک نکته']) ->assertOk();                       // teachers unaffected
    }

    public function test_daily_limits_and_school_budget(): void
    {
        $this->enable();
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['ai.daily_limit_student' => 2]]);
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'a'])->assertOk();
        $this->postJson('/api/v1/ai/ask', ['message' => 'b'])->assertOk();
        $this->postJson('/api/v1/ai/ask', ['message' => 'c'])->assertStatus(429)->assertJsonPath('code', 'ai_user_limit');
        $this->as_($this->u2)->postJson('/api/v1/ai/ask', ['message' => 'a'])->assertOk();                                  // per-user, not per-school

        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['ai.daily_token_budget' => 100]]);
        $this->as_($this->teacher)->postJson('/api/v1/ai/ask', ['message' => 'd'])->assertStatus(429)->assertJsonPath('code', 'ai_school_budget');
    }

    public function test_provider_errors_are_handled_logged_and_do_not_count_as_usage(): void
    {
        $this->enable();
        FakeAiProvider::$fail = true;
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertStatus(502)->assertJsonPath('code', 'ai_error');
        $this->assertSame('error', AiRequest::withoutGlobalScopes()->first()->status);
        $this->assertSame(0, AiUsageRecord::withoutGlobalScopes()->count());
        FakeAiProvider::$fail = false;
        $this->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertOk();                                                  // service recovers
        $u = AiUsageRecord::withoutGlobalScopes()->first();
        $this->assertSame([1, 100, 50], [$u->requests, $u->input_tokens, $u->output_tokens]);
    }

    public function test_answers_are_grounded_in_approved_school_material_with_visible_sources(): void
    {
        $this->enable();
        $t = $this->as_($this->teacher);
        $t->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'فتوسنتز', 'kind' => 'text', 'body' => 'فتوسنتز فرایندی است که گیاهان نور خورشید را به انرژی تبدیل می‌کنند.']);
        $t->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'محرمانه', 'kind' => 'text', 'body' => 'فتوسنتز متن غیرمجاز', 'ai_indexable' => false]);
        $t->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'پیش‌نویس فتوسنتز', 'kind' => 'text', 'body' => 'فتوسنتز', 'publish' => false]);

        $r = $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'فتوسنتز چیست؟', 'use_sources' => true])->assertOk()->json();
        $this->assertSame(['فتوسنتز'], array_column($r['sources'], 'title'));                  // not the non-indexable, not the unpublished
        $this->assertTrue($r['grounded']);
        $this->assertStringContainsString('[S1]', FakeAiProvider::$sent[0]['system']);

        // other class's material is not usable
        $this->as_($this->outsiderUser)->postJson('/api/v1/ai/ask', ['message' => 'فتوسنتز چیست؟', 'use_sources' => true, 'grounded_only' => true])->assertOk()->assertJsonPath('grounded', false);
        $this->assertCount(1, FakeAiProvider::$sent);                                          // grounded_only with no source → model not called at all
    }

    public function test_teacher_tools_return_drafts_and_never_set_scores(): void
    {
        $this->enable();
        FakeAiProvider::$reply = json_encode(['questions' => [['type' => 'tf', 'body' => 'x', 'accepted_answers' => [true], 'points' => 1]]]);
        $this->as_($this->teacher)->postJson('/api/v1/ai/teacher/questions', ['subject_id' => $this->fx['math']->id, 'topic' => 'جمع', 'count' => 1])->assertOk()->assertJsonPath('draft', true)->assertJsonCount(1, 'questions');
        $this->assertSame(0, \App\Models\Question::withoutGlobalScopes()->count());                  // nothing saved by itself

        FakeAiProvider::$reply = "```json\n".json_encode(['criteria' => [['title' => 'بیان', 'max' => 2, 'description' => '']]])."\n```";
        $this->postJson('/api/v1/ai/teacher/rubric', ['question' => 'فیثاغورس؟'])->assertOk()->assertJsonPath('rubric.criteria.0.title', 'بیان');   // fenced JSON tolerated
        FakeAiProvider::$reply = 'نه json';
        $this->postJson('/api/v1/ai/teacher/rubric', ['question' => 'فیثاغورس؟'])->assertStatus(502)->assertJsonPath('code', 'ai_bad_output');
        $this->as_($this->u1)->postJson('/api/v1/ai/teacher/rubric', ['question' => 'x'])->assertForbidden();
    }

    public function test_grade_suggestion_is_advisory_only(): void
    {
        $this->enable();
        $q = $this->as_($this->teacher)->postJson('/api/v1/questions', ['subject_id' => $this->fx['math']->id, 'type' => 'essay', 'body' => 'شرح دهید', 'points' => 4, 'rubric' => 'معیار'])->json('data.id');
        $exam = $this->postJson('/api/v1/exams', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'آ', 'start_at' => now()->addMinutes(1)->toIso8601String(),
            'end_at' => now()->addHour()->toIso8601String(), 'duration_minutes' => 30, 'questions' => [['question_id' => $q]]])->json('data.id');
        $this->postJson("/api/v1/exams/$exam/publish");
        $this->travelTo(now()->addMinutes(2));
        $aid = $this->as_($this->u1)->postJson("/api/v1/exams/$exam/start")->json('attempt.id');
        $this->putJson("/api/v1/exam-attempts/$aid/answers", ['answers' => [['question_id' => $q, 'answer' => ['text' => 'پاسخ دانش‌آموز نام1001']]]]);
        $this->postJson("/api/v1/exam-attempts/$aid/submit");
        $answer = ExamAnswer::withoutGlobalScopes()->first();

        FakeAiProvider::$reply = json_encode(['suggested_score' => 99, 'strengths' => ['خوب'], 'mistakes' => [], 'rationale' => 'چون']);
        $r = $this->as_($this->teacher)->postJson("/api/v1/ai/teacher/answers/{$answer->id}/grade-suggestion")->assertOk()->json();
        $this->assertSame(4.0, (float) $r['suggestion']['suggested_score']);                         // clamped to the question's max
        $this->assertTrue($r['suggestion']['requires_human_review']);
        $this->assertFalse($r['applied']);
        $answer->refresh();
        $this->assertNull($answer->manual_score);                                                    // AI never writes the score
        $this->assertSame('submitted', \App\Models\ExamAttempt::withoutGlobalScopes()->first()->status);
        $this->as_($this->u1)->postJson("/api/v1/ai/teacher/answers/{$answer->id}/grade-suggestion")->assertForbidden();
    }

    public function test_admin_summary_sends_aggregates_not_names_and_usage_report_works(): void
    {
        $this->enable();
        $this->as_($this->deputy)->postJson('/api/v1/ai/admin/summary')->assertOk()->assertJsonStructure(['summary', 'stats']);
        $sent = json_encode(FakeAiProvider::$sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('نام1001', $sent);
        $this->getJson('/api/v1/ai/usage')->assertOk()->assertJsonPath('totals.requests', 1);
        $this->as_($this->teacher)->getJson('/api/v1/ai/usage')->assertForbidden();
    }

    public function test_user_can_erase_their_ai_log_and_voice_reports_unsupported(): void
    {
        $this->enable();
        $this->as_($this->u1)->postJson('/api/v1/ai/ask', ['message' => 'x'])->assertOk();
        $this->deleteJson('/api/v1/ai/my-data')->assertOk()->assertJsonPath('deleted', 1);
        $audio = \Illuminate\Http\UploadedFile::fake()->create('q.mp3', 10, 'audio/mpeg');
        $this->postJson('/api/v1/ai/transcribe', ['audio' => $audio])->assertStatus(501)->assertJsonPath('code', 'ai_audio_unsupported');
    }

    public function test_anonymizer_masks_identifiers(): void
    {
        $t = Anonymizer::scrub('تماس: 0912 123 4567 یا ۰۹۱۲۳۴۵۶۷۸۹، mail x.y@z.org، https://evil.test/a?b=1 کد ۰۰۱۲۳۴۵۶۷۸', ['علی رضایی']);
        $this->assertStringNotContainsString('x.y@z.org', $t);
        $this->assertStringNotContainsString('evil.test', $t);
        $this->assertStringContainsString('[کد ملی]', $t);
        $this->assertStringContainsString('[نام]', Anonymizer::scrub('علی رضایی سلام', ['علی رضایی']));
    }
}

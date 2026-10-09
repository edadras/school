<?php

namespace Tests\Feature;

use App\Models\GradeRecord;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\World;
use Tests\TestCase;

class FilesAndAssignmentsTest extends TestCase
{
    use RefreshDatabase, World;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildWorld();
    }

    private function png(string $name = 'a.png'): UploadedFile
    {
        // 1x1 PNG
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function newAssignment(array $over = []): int
    {
        return $this->as_($this->teacher)->postJson('/api/v1/assignments', $over + [
            'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'تمرین فصل ۳', 'description' => 'حل کنید',
            'answer_types' => ['text', 'file'], 'max_score' => 20, 'due_at' => now()->addDays(2)->toIso8601String(), 'publish' => true,
        ])->assertCreated()->json('data.id');
    }

    public function test_upload_validates_real_content_not_client_claim(): void
    {
        $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?>');
        $this->as_($this->u1)->postJson('/api/v1/files', ['file' => $fake])->assertStatus(422);
        $this->postJson('/api/v1/files', ['file' => $this->png()])->assertCreated()->assertJsonPath('data.mime', 'image/png');
    }

    public function test_unattached_file_is_private_and_links_are_signed_and_expire(): void
    {
        $id = $this->as_($this->u1)->postJson('/api/v1/files', ['file' => $this->png()])->json('data.id');
        $this->as_($this->u2)->getJson("/api/v1/files/$id/link")->assertNotFound();           // other student: no access
        $url = $this->as_($this->u1)->getJson("/api/v1/files/$id/link")->assertOk()->json('url');

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');       // no login needed: the signature is the credential
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
        $this->travel(10)->minutes();
        $this->get($url)->assertForbidden();                                                   // expired
    }

    public function test_storage_quota_and_size_limit(): void
    {
        \App\Models\SchoolSubscription::where('school_id', $this->school->id)->update(['max_storage_mb' => 0]);
        $this->as_($this->u1)->postJson('/api/v1/files', ['file' => $this->png()])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_full_assignment_lifecycle(): void
    {
        $id = $this->newAssignment();

        // student sees it and opening it marks it viewed
        $this->as_($this->u1)->getJson('/api/v1/assignments')->assertOk()->assertJsonPath('data.0.state', 'published');
        $this->getJson("/api/v1/assignments/$id")->assertOk()->assertJsonPath('my_submission.status', 'viewed');
        // draft
        $this->putJson("/api/v1/assignments/$id/draft", ['text_answer' => 'نیمه‌کاره'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        // file answer
        $fid = $this->postJson('/api/v1/files', ['file' => $this->png('ans.png')])->json('data.id');
        $sid = $this->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'جواب نهایی', 'file_ids' => [$fid]])->assertOk()->assertJsonPath('data.status', 'submitted')->json('data.id');

        // other student and outsider cannot read this answer's file; the teacher and the owner can
        $this->as_($this->u2)->getJson("/api/v1/files/$fid/link")->assertNotFound();
        $this->as_($this->outsiderUser)->getJson("/api/v1/files/$fid/link")->assertNotFound();
        $this->as_($this->teacher)->getJson("/api/v1/files/$fid/link")->assertOk();
        $this->as_($this->u1)->getJson("/api/v1/files/$fid/link")->assertOk();

        // teacher returns it for revision, student resubmits
        $this->as_($this->teacher)->postJson("/api/v1/submissions/$sid/grade", ['outcome' => 'return', 'feedback' => 'مرحلهٔ ۲ را کامل کن'])->assertOk()->assertJsonPath('data.status', 'needs_revision');
        $this->as_($this->u1)->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'اصلاح‌شده'])->assertOk();
        $this->assertSame(2, Submission::withoutGlobalScopes()->find($sid)->attempt);
        $this->as_($this->teacher)->getJson("/api/v1/submissions/$sid/history")->assertOk()->assertJsonCount(2, 'data');

        // finalize with score → DRAFT grade record (approval required), student can't see it yet
        $this->postJson("/api/v1/submissions/$sid/grade", ['outcome' => 'finalize', 'score' => 17.5, 'feedback' => 'آفرین'])->assertOk()->assertJsonPath('data.status', 'finalized');
        $g = GradeRecord::withoutGlobalScopes()->first();
        $this->assertSame('draft', $g->status);
        $this->as_($this->u1)->getJson('/api/v1/grades')->assertJsonCount(0, 'data');
        $this->as_($this->deputy)->postJson("/api/v1/grades/{$g->id}/approve")->assertOk();
        $this->as_($this->u1)->getJson('/api/v1/grades')->assertJsonCount(1, 'data');
        // finalized work cannot be edited
        $this->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'x'])->assertStatus(422);
    }

    public function test_late_policy_and_closed_assignments(): void
    {
        $id = $this->newAssignment(['allow_late' => false]);
        $this->travel(3)->days();
        $this->as_($this->u1)->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'دیر'])->assertStatus(422);

        $this->travelBack();
        $id2 = $this->newAssignment(['title' => 'دیرپذیر', 'allow_late' => true]);
        $this->travel(3)->days();
        $this->as_($this->u1)->postJson("/api/v1/assignments/$id2/submit", ['text_answer' => 'دیر'])->assertOk()->assertJsonPath('data.status', 'late')->assertJsonPath('data.is_late', true);
    }

    public function test_answer_type_restrictions_and_empty_submission(): void
    {
        $id = $this->newAssignment(['answer_types' => ['file']]);
        $this->as_($this->u1)->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'متن مجاز نیست'])->assertStatus(422);
        $this->postJson("/api/v1/assignments/$id/submit", [])->assertStatus(422);
    }

    public function test_draft_assignments_and_other_classes_are_invisible_to_students(): void
    {
        $draft = $this->as_($this->teacher)->postJson('/api/v1/assignments', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'پیش‌نویس', 'answer_types' => ['text']])->json('data.id');
        $this->as_($this->u1)->getJson("/api/v1/assignments/$draft")->assertNotFound();
        $pub = $this->newAssignment();
        $this->as_($this->outsiderUser)->getJson("/api/v1/assignments/$pub")->assertNotFound();
        $this->postJson("/api/v1/assignments/$pub/submit", ['text_answer' => 'x'])->assertNotFound();
    }

    public function test_teacher_cannot_create_for_classes_they_do_not_teach(): void
    {
        $this->as_($this->teacher)->postJson('/api/v1/assignments', ['section_id' => $this->section2->id, 'subject_id' => $this->fx['math']->id, 'title' => 'x', 'answer_types' => ['text']])->assertForbidden();
    }

    public function test_guardian_sees_child_assignment_state_but_cannot_submit(): void
    {
        $id = $this->newAssignment();
        $this->as_($this->guardian)->getJson('/api/v1/assignments')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/assignments/$id/submit", ['text_answer' => 'x'])->assertForbidden();   // no learn.participate
    }

    public function test_materials_visible_only_when_published_and_in_own_section(): void
    {
        $this->as_($this->teacher)->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'درس‌نامه', 'kind' => 'text', 'body' => 'متن درس'])->assertCreated();
        $this->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'پنهان', 'kind' => 'text', 'body' => 'x', 'publish' => false])->assertCreated();
        $this->postJson('/api/v1/materials', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'لینک', 'kind' => 'link', 'body' => 'javascript:alert(1)'])->assertStatus(422);

        $this->as_($this->u1)->getJson('/api/v1/materials')->assertJsonCount(1, 'data');
        $this->as_($this->outsiderUser)->getJson('/api/v1/materials')->assertJsonCount(0, 'data');
        $this->as_($this->teacher)->getJson('/api/v1/materials')->assertJsonCount(2, 'data');
    }
}

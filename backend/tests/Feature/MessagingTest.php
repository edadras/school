<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\World;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase, World;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildWorld();
    }

    private function classChat(\App\Models\User $u): int
    {
        return collect($this->as_($u)->getJson('/api/v1/conversations')->assertOk()->json('data'))->firstWhere('type', 'class')['id'];
    }

    public function test_class_chat_contains_only_the_class_students_and_teachers(): void
    {
        $c = $this->classChat($this->u1);
        $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'سلام'])->assertCreated();
        $this->as_($this->u2)->getJson("/api/v1/conversations/$c/messages")->assertOk()->assertJsonPath('data.0.body', 'سلام');
        $this->as_($this->teacher)->getJson("/api/v1/conversations/$c/messages")->assertOk();
        $this->as_($this->outsiderUser)->getJson("/api/v1/conversations/$c/messages")->assertNotFound();
        $this->as_($this->guardian)->getJson("/api/v1/conversations/$c/messages")->assertNotFound();      // guardians are not in the students' chat
        $this->as_($this->outsiderUser)->postJson("/api/v1/conversations/$c/messages", ['body' => 'نفوذ'])->assertNotFound();
    }

    public function test_send_is_idempotent_for_retries_and_supports_replies_and_unread(): void
    {
        $c = $this->classChat($this->u1);
        $a = $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'یک', 'client_id' => 'abc'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/conversations/$c/messages", ['body' => 'یک', 'client_id' => 'abc'])->assertOk()->assertJsonPath('data.id', $a);   // same message, no duplicate
        $this->assertSame(1, Message::withoutGlobalScopes()->count());

        $this->as_($this->u2)->postJson("/api/v1/conversations/$c/messages", ['body' => 'پاسخ', 'reply_to_id' => $a])->assertCreated()->assertJsonPath('data.reply_to_id', $a);
        $this->postJson("/api/v1/conversations/$c/messages", ['body' => 'x', 'reply_to_id' => 99999])->assertStatus(422);
        $list = $this->as_($this->u1)->getJson('/api/v1/conversations')->json('data');
        $this->assertSame(1, collect($list)->firstWhere('id', $c)['unread']);
        $this->getJson("/api/v1/conversations/$c/messages")->assertOk();                                          // opening marks read
        $this->assertSame(0, collect($this->getJson('/api/v1/conversations')->json('data'))->firstWhere('id', $c)['unread']);
        // reconnect sync
        $this->getJson("/api/v1/conversations/$c/messages?after_id=$a&mark_read=0")->assertJsonCount(1, 'data');
    }

    public function test_attachments_are_private_to_the_conversation(): void
    {
        $c = $this->classChat($this->u1);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $fid = $this->as_($this->u1)->postJson('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('p.png', $png)])->json('data.id');
        $this->postJson("/api/v1/conversations/$c/messages", ['file_ids' => [$fid]])->assertCreated()->assertJsonPath('data.kind', 'file');
        $this->as_($this->u2)->getJson("/api/v1/files/$fid/link")->assertOk();                      // class participant
        $this->as_($this->outsiderUser)->getJson("/api/v1/files/$fid/link")->assertNotFound();
        $this->as_($this->u2)->postJson("/api/v1/conversations/$c/messages", ['file_ids' => [$fid]])->assertStatus(422);   // can't re-use someone else's upload
    }

    public function test_direct_messages_follow_school_policy(): void
    {
        $this->as_($this->u1)->postJson('/api/v1/conversations/direct', ['user_id' => $this->u2->id])->assertStatus(422);            // student↔student off
        $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->teacher->id])->assertStatus(422);                         // student↔teacher off
        $this->as_($this->guardian)->postJson('/api/v1/conversations/direct', ['user_id' => $this->teacher->id])->assertCreated();    // guardian↔teacher on
        $this->as_($this->u1)->postJson('/api/v1/conversations/direct', ['user_id' => $this->guardian->id])->assertStatus(422);

        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['messaging.student_to_teacher' => true]])->assertOk();
        $d = $this->as_($this->u1)->postJson('/api/v1/conversations/direct', ['user_id' => $this->teacher->id])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/conversations/direct', ['user_id' => $this->teacher->id])->assertCreated()->assertJsonPath('data.id', $d);   // same chat, not a new one
        $this->as_($this->u2)->getJson("/api/v1/conversations/$d/messages")->assertNotFound();
        $this->as_($this->admin)->postJson('/api/v1/conversations/direct', ['user_id' => $this->u1->id])->assertCreated();            // staff may always reach students
    }

    public function test_assignment_thread_between_student_and_teacher_bypasses_dm_restriction(): void
    {
        $aid = $this->as_($this->teacher)->postJson('/api/v1/assignments', ['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'title' => 'ت', 'answer_types' => ['text'], 'publish' => true])->json('data.id');
        $c = $this->as_($this->u1)->postJson('/api/v1/conversations/direct', ['user_id' => $this->teacher->id, 'assignment_id' => $aid])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/conversations/$c/messages", ['body' => 'سؤال دربارهٔ تمرین'])->assertCreated();
    }

    public function test_messenger_can_be_disabled_or_limited_to_hours_and_lessons(): void
    {
        $c = $this->classChat($this->u1);
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['messaging.enabled' => false]]);
        $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('messaging');
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['messaging.enabled' => true, 'messaging.quiet_hours' => [['start' => '00:00', 'end' => '23:59']]]]);
        $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'x'])->assertStatus(422);
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['messaging.quiet_hours' => []]]);
        $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'ok'])->assertCreated();
    }

    public function test_teacher_can_lock_class_chat(): void
    {
        $c = $this->classChat($this->u1);
        $this->as_($this->u1)->putJson("/api/v1/conversations/$c/lock", ['locked' => true])->assertForbidden();
        $this->as_($this->teacher)->putJson("/api/v1/conversations/$c/lock", ['locked' => true])->assertOk();
        $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'x'])->assertStatus(422);
        $this->as_($this->teacher)->postJson("/api/v1/conversations/$c/messages", ['body' => 'اعلام معلم'])->assertCreated();
    }

    public function test_report_and_moderation_flow_with_audit(): void
    {
        $c = $this->classChat($this->u1);
        $m = $this->as_($this->u1)->postJson("/api/v1/conversations/$c/messages", ['body' => 'متن نامناسب'])->json('data.id');
        $this->as_($this->u2)->postJson("/api/v1/messages/$m/report", ['reason' => 'توهین'])->assertCreated();
        $this->postJson("/api/v1/messages/$m/report", ['reason' => 'توهین'])->assertCreated();                       // same reporter again: no duplicate row
        $this->assertSame(1, MessageReport::withoutGlobalScopes()->count());
        $this->as_($this->outsiderUser)->postJson("/api/v1/messages/$m/report", ['reason' => 'x'])->assertNotFound();

        $this->as_($this->u2)->getJson('/api/v1/moderation/reports')->assertForbidden();
        $rid = $this->as_($this->deputy)->getJson('/api/v1/moderation/reports')->assertOk()->json('data.0.id');
        $this->getJson("/api/v1/moderation/reports/$rid")->assertOk()->assertJsonPath('message.body', 'متن نامناسب');
        $this->postJson("/api/v1/moderation/reports/$rid/resolve", ['action' => 'delete_message'])->assertOk();
        $this->postJson("/api/v1/moderation/reports/$rid/resolve", ['action' => 'dismiss'])->assertStatus(422);          // already handled
        $this->as_($this->u1)->getJson("/api/v1/conversations/$c/messages")->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.body', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.report_viewed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.report_resolved']);
    }
}

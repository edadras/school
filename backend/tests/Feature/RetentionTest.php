<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Message;
use App\Models\OutboxNotification;
use App\Models\StoredFile;
use App\Modules\Files\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    private $school;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedRbac();
        $this->school = $this->makeSchool('a');
        $this->admin = $this->makeMember($this->school, 'school_admin');
    }

    private function file(string $age, ?string $ctx = null): StoredFile
    {
        Storage::disk('local')->put($p = 'f/'.uniqid().'.png', 'x');

        return $this->inSchool($this->school, function () use ($p, $age, $ctx) {
            $f = StoredFile::create(['user_id' => $this->admin->id, 'disk' => 'local', 'path' => $p, 'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 1, 'context_type' => $ctx, 'context_id' => $ctx ? 1 : null]);
            $f->forceFill(['created_at' => now()->modify($age)])->save();

            return $f;
        });
    }

    public function test_nothing_is_deleted_without_a_policy_except_old_orphan_uploads(): void
    {
        $old = $this->file('-10 days');                 // never attached
        $recent = $this->file('-1 day');
        $kept = $this->file('-400 days', 'assignment');
        $out = app(RetentionService::class)->run($this->school);
        $this->assertSame(1, $out['orphan_files']);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($recent->path);
        Storage::disk('local')->assertExists($kept->path);
        $this->assertSame(0, $out['messages'] + $out['notifications']);
    }

    public function test_school_policy_deletes_old_notifications_but_dry_run_only_counts(): void
    {
        $this->inSchool($this->school, function () {
            $this->school->forceFill([])->save();
            DB::table('notifications_outbox')->insert([
                ['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'type' => 't', 'dedupe_key' => 'a', 'title' => 'old read', 'read_at' => now()->subDays(100), 'created_at' => now()->subDays(100), 'updated_at' => now()],
                ['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'type' => 't', 'dedupe_key' => 'b', 'title' => 'old unread', 'read_at' => null, 'created_at' => now()->subDays(100), 'updated_at' => now()],
                ['school_id' => $this->school->id, 'user_id' => $this->admin->id, 'type' => 't', 'dedupe_key' => 'c', 'title' => 'new read', 'read_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);
        });
        $this->as($this->admin, $this->school)->patchJson('/api/v1/school/settings', ['settings' => ['retention.notifications_days' => 10]])->assertStatus(422);   // below the 30-day floor
        $this->as($this->admin, $this->school)->patchJson('/api/v1/school/settings', ['settings' => ['retention.notifications_days' => 90]])->assertOk();

        $dry = app(RetentionService::class)->run($this->school, dry: true);
        $this->assertSame(1, $dry['notifications']);
        $this->assertSame(3, OutboxNotification::withoutGlobalScopes()->count());

        app(RetentionService::class)->run($this->school);
        $this->assertSame(2, OutboxNotification::withoutGlobalScopes()->count());                  // old unread and new read stay
        $this->assertSame(0, app(RetentionService::class)->run($this->school)['notifications']);   // idempotent
        $this->assertTrue(AuditLog::where('action', 'retention.purged')->exists());
    }

    public function test_message_policy_removes_old_messages_and_their_files_only(): void
    {
        $att = $this->file('-1 day', 'message');
        $keep = $this->file('-1 day', 'message');
        $conv = DB::table('conversations')->insertGetId(['school_id' => $this->school->id, 'type' => 'class', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn ($age, $body) => DB::table('messages')->insertGetId(['school_id' => $this->school->id, 'conversation_id' => $conv, 'user_id' => $this->admin->id, 'kind' => 'text', 'body' => $body, 'created_at' => now()->modify($age), 'updated_at' => now()]);
        $old = $mk('-200 days', 'old');
        $new = $mk('-1 day', 'new');
        DB::table('message_attachments')->insert([['school_id' => $this->school->id, 'message_id' => $old, 'file_id' => $att->id], ['school_id' => $this->school->id, 'message_id' => $new, 'file_id' => $keep->id]]);
        $this->as($this->admin, $this->school)->patchJson('/api/v1/school/settings', ['settings' => ['retention.messages_days' => 90]])->assertOk();

        $out = app(RetentionService::class)->run($this->school);
        $this->assertSame(1, $out['messages']);
        $this->assertSame(['new'], DB::table('messages')->pluck('body')->all());
        Storage::disk('local')->assertMissing($att->path);
        Storage::disk('local')->assertExists($keep->path);
    }

    public function test_policies_are_per_school(): void
    {
        $other = $this->makeSchool('b');
        $this->as($this->admin, $this->school)->patchJson('/api/v1/school/settings', ['settings' => ['retention.notifications_days' => 30]])->assertOk();
        $otherAdmin = $this->makeMember($other, 'school_admin');
        DB::table('notifications_outbox')->insert(['school_id' => $other->id, 'user_id' => $otherAdmin->id, 'type' => 't', 'dedupe_key' => 'z', 'title' => 'x', 'read_at' => now()->subDays(200), 'created_at' => now()->subDays(200), 'updated_at' => now()]);
        app(RetentionService::class)->run($this->school);
        $this->assertSame(1, OutboxNotification::withoutGlobalScopes()->where('school_id', $other->id)->count());   // school b has no policy
    }

    public function test_command_runs_for_all_active_schools(): void
    {
        $this->artisan('retention:run --dry-run')->assertExitCode(0);
    }
}

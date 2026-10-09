<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\OutboxNotification;
use App\Models\Student;
use App\Modules\Realtime\ChannelAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;
use Tests\TestCase;

class AdminPlatformTest extends TestCase
{
    use RefreshDatabase, World;

    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->root = $this->makePlatformAdmin();
    }

    private function csv(string $content, string $name = 'f.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ------------------------------------------------------------------ platform
    public function test_platform_stats_health_and_honest_unconfigured_services(): void
    {
        $this->as($this->root)->getJson('/api/v1/platform/stats')->assertOk()->assertJsonPath('totals.schools', 1)->assertJsonPath('schools.0.students', 3)->assertJsonPath('schools.0.plan', 'free');
        $h = $this->getJson('/api/v1/platform/health')->assertOk()->json();
        $this->assertTrue($h['database']['ok']);
        $this->assertFalse($h['media']['ok']);          // MEDIA_PROVIDER=none → reported, not faked
        $this->assertFalse($h['ai']['configured']);
        $this->assertFalse($h['push']['ok']);
        $this->as($this->admin, $this->school)->getJson('/api/v1/platform/stats')->assertForbidden();
    }

    public function test_platform_announcement_reaches_each_school_admin_and_operators_are_managed(): void
    {
        $this->as($this->root)->postJson('/api/v1/platform/announcements', ['title' => 'نگهداری', 'body' => 'شنبه شب'])->assertCreated()->assertJsonPath('notified', 1);
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'platform.announcement')->where('user_id', $this->admin->id)->exists());

        $id = $this->postJson('/api/v1/platform/operators', ['name' => 'پشتیبان', 'email' => 'sup@example.test', 'role' => 'support', 'password' => 'Str0ng-Password12'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/platform/operators', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'support', 'password' => 'short'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['login' => 'sup@example.test', 'password' => 'Str0ng-Password12'])->assertOk();
        $this->patchJson("/api/v1/platform/operators/$id", ['status' => 'disabled'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'sup@example.test', 'password' => 'Str0ng-Password12'])->assertStatus(422);
        $this->patchJson("/api/v1/platform/operators/{$this->root->id}", ['status' => 'disabled'])->assertStatus(422);       // can't lock yourself out
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.operator_created']);
    }

    public function test_platform_settings_validation_and_audit_trail(): void
    {
        $this->as($this->root)->patchJson('/api/v1/platform/settings', ['settings' => ['default_timezone' => 'Mars/Base']])->assertStatus(422);
        $this->patchJson('/api/v1/platform/settings', ['settings' => ['default_timezone' => 'Asia/Tehran', 'registration_open' => true]])->assertOk()->assertJsonPath('data.registration_open.0', true);
        $this->getJson('/api/v1/platform/audit?action=platform')->assertOk();
    }

    // ------------------------------------------------------------------ support
    public function test_support_access_requires_school_admin_grant_is_read_only_expires_and_is_audited(): void
    {
        $support = \App\Models\User::create(['name' => 'پشتیبان', 'email' => 'sup@example.test', 'password' => 'Str0ng-Password12']);
        $support->forceFill(['platform_role' => 'support'])->save();

        $this->as($support, $this->school)->getJson('/api/v1/academics/grades')->assertForbidden()->assertJsonPath('code', 'support_access_denied');
        $this->as_($this->teacher)->postJson('/api/v1/support/grants', ['support_user_id' => $support->id, 'reason' => 'x', 'hours' => 2])->assertForbidden();
        $gid = $this->as_($this->admin)->postJson('/api/v1/support/grants', ['support_user_id' => $support->id, 'reason' => 'بررسی خطای تقویم', 'hours' => 2])->assertCreated()->json('data.id');

        $this->as($support, $this->school)->getJson('/api/v1/academics/grades')->assertOk();
        $this->postJson('/api/v1/academics/grades', ['name' => 'نفوذ'])->assertForbidden();                         // read-only
        $this->getJson('/api/v1/grades')->assertForbidden();                                                        // no student data (grades) at all
        $this->getJson('/api/v1/report-cards')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.data_access']);

        $this->as_($this->admin)->deleteJson("/api/v1/support/grants/$gid")->assertOk();
        $this->as($support, $this->school)->getJson('/api/v1/academics/grades')->assertForbidden();
        $this->as_($this->admin)->postJson('/api/v1/support/grants', ['support_user_id' => $support->id, 'reason' => 'دوباره', 'hours' => 1]);
        $this->travel(2)->hours();
        $this->as($support, $this->school)->getJson('/api/v1/academics/grades')->assertForbidden();               // expired
        $this->as($support, $this->makeSchool('other'))->getJson('/api/v1/academics/grades')->assertForbidden();   // other school never granted
    }

    public function test_tickets_flow_between_school_user_and_platform_support(): void
    {
        $id = $this->as_($this->u1)->postJson('/api/v1/support/tickets', ['subject' => 'دوربین کار نمی‌کند', 'body' => 'مشکل دارم', 'category' => 'technical'])->assertCreated()->json('data.id');
        $this->as_($this->u2)->getJson("/api/v1/support/tickets/$id")->assertNotFound();
        $this->as($this->root)->getJson('/api/v1/platform/support/tickets?status=open')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/platform/support/tickets/$id/reply", ['body' => 'یادداشت داخلی', 'internal' => true])->assertCreated();
        $this->postJson("/api/v1/platform/support/tickets/$id/reply", ['body' => 'لطفاً مرورگر را به‌روز کنید', 'status' => 'pending'])->assertCreated();
        $msgs = $this->as_($this->u1)->getJson("/api/v1/support/tickets/$id")->assertOk()->json('messages');
        $this->assertCount(2, $msgs);                                                                                // internal note hidden
        $this->postJson("/api/v1/support/tickets/$id/reply", ['body' => 'ممنون'])->assertCreated();
        $this->assertSame('open', \App\Models\SupportTicket::find($id)->status);
        $this->as_($this->u1)->getJson('/api/v1/platform/support/tickets')->assertForbidden();
    }

    // ------------------------------------------------------------------ import/export
    public function test_student_import_reports_row_errors_and_creates_valid_rows(): void
    {
        $csv = "student_code,first_name,last_name,birth_date,grade,section,guardian_name,guardian_email,guardian_phone\n"
            ."۳۰۰۱,مریم,کریمی,2015-03-01,هفتم,الف,مادر مریم,mother@example.test,09121112233\n"
            ."1001,تکراری,x,,,,,,\n"                       // duplicate code of existing student
            .",بدون کد,x,,,,,,\n"                          // missing code
            ."3003,ناموجود,x,,هشتم,الف,,,\n"                // unknown grade
            ."3004,علی,احمدی,2999-01-01,,,,,\n";           // future birth date
        $r = $this->as_($this->deputy)->postJson('/api/v1/imports/students', ['file' => $this->csv($csv), 'initial_password' => 'Init-Pass-12345'])->json('data');
        $this->assertSame([5, 1, 4], [$r['total'], $r['created'], $r['failed']]);
        $this->assertEqualsCanonicalizing([3, 4, 5, 6], array_column($r['errors'], 'line'));                      // line numbers include the header
        $this->assertNotNull(Student::withoutGlobalScopes()->where('student_code', '3001')->first());               // Persian digits normalised
        $this->assertNotNull(\App\Models\User::where('email', 'mother@example.test')->first());
        $this->assertTrue(\App\Models\StudentGuardian::withoutGlobalScopes()->where('status', 'approved')->count() >= 2);
        $this->assertStringNotContainsString('Init-Pass', json_encode($r));                                         // password never echoed
    }

    public function test_import_dry_run_writes_nothing_and_limits_apply(): void
    {
        $csv = "student_code,first_name,last_name\n5001,الف,ب\n5002,ج,د\n";
        $before = Student::withoutGlobalScopes()->count();
        $this->as_($this->deputy)->postJson('/api/v1/imports/students', ['file' => $this->csv($csv), 'dry_run' => true, 'initial_password' => 'Init-Pass-12345'])->assertCreated()->assertJsonPath('data.created', 2);
        $this->assertSame($before, Student::withoutGlobalScopes()->count());

        \App\Models\SchoolSubscription::where('school_id', $this->school->id)->update(['max_students' => $before + 1]);
        $r = $this->postJson('/api/v1/imports/students', ['file' => $this->csv($csv), 'initial_password' => 'Init-Pass-12345'])->json('data');
        $this->assertSame([1, 1], [$r['created'], $r['failed']]);                                                  // quota stops the second row
        $this->as_($this->teacher)->postJson('/api/v1/imports/students', ['file' => $this->csv($csv), 'initial_password' => 'Init-Pass-12345'])->assertForbidden();
        $this->as_($this->deputy)->postJson('/api/v1/imports/students', ['file' => $this->csv($csv, 'a.exe'), 'initial_password' => 'Init-Pass-12345'])->assertStatus(422);
        $this->postJson('/api/v1/imports/students', ['file' => $this->csv($csv), 'initial_password' => 'weak'])->assertStatus(422);
    }

    public function test_teacher_import_and_export_is_audited_and_formula_safe(): void
    {
        $this->as_($this->deputy)->postJson('/api/v1/imports/teachers', ['file' => $this->csv("name,email,phone,personnel_code\nمعلم نو,new@example.test,,P1\n"), 'initial_password' => 'Init-Pass-12345'])->assertCreated()->assertJsonPath('data.created', 1);

        Student::withoutGlobalScopes()->where('id', $this->s1->id)->update(['first_name' => '=HYPERLINK("http://evil")']);
        $csv = $this->get('/api/v1/exports/students');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $body);                                                    // neutralised
        $this->assertStringContainsString('1002', $body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.students']);
        $this->as_($this->teacher)->get('/api/v1/exports/students')->assertForbidden();
        $this->get('/api/v1/imports/students/template')->assertForbidden();
        $this->as_($this->deputy)->get('/api/v1/imports/students/template')->assertOk();
    }

    // ------------------------------------------------------------------ school admin
    public function test_settings_whitelist_profile_and_audit_view(): void
    {
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['messaging.enabled' => 'yes']])->assertStatus(422);
        $this->patchJson('/api/v1/school/settings', ['settings' => ['evil.key' => true]])->assertStatus(422);
        $this->patchJson('/api/v1/school/settings', ['settings' => ['files.max_mb' => 5, 'messaging.quiet_hours' => [['start' => '22:00', 'end' => '06:00']]]])->assertOk();
        $this->patchJson('/api/v1/school/profile', ['timezone' => 'Europe/Istanbul', 'calendar' => 'gregorian'])->assertOk();
        $this->getJson('/api/v1/school/profile')->assertJsonPath('data.timezone', 'Europe/Istanbul');
        $this->assertSame(5, $this->getJson('/api/v1/school/profile')->json('settings')['files.max_mb']);
        $this->as_($this->deputy)->patchJson('/api/v1/school/settings', ['settings' => ['files.max_mb' => 99]])->assertForbidden();
        $this->as_($this->admin)->getJson('/api/v1/school/audit?action=school.')->assertOk()->assertJsonPath('total', 2);
        $this->as_($this->deputy)->getJson('/api/v1/school/audit')->assertForbidden();
    }

    public function test_announcements_reach_only_their_audience(): void
    {
        $this->as_($this->deputy)->postJson('/api/v1/announcements', ['title' => 'جلسه اولیا', 'body' => 'شنبه', 'audience_type' => 'section', 'audience_id' => $this->fx['section']->id])->assertCreated();
        $this->postJson('/api/v1/announcements', ['title' => 'کل مدرسه', 'body' => 'تعطیل', 'audience_type' => 'school'])->assertCreated();
        $this->postJson('/api/v1/announcements', ['title' => 'x', 'body' => 'x', 'audience_type' => 'section', 'audience_id' => 999999])->assertStatus(422);
        $this->postJson('/api/v1/announcements', ['title' => 'خانواده', 'body' => 'پیام', 'audience_type' => 'student', 'audience_id' => $this->s1->id])->assertCreated();

        $titles = fn ($u) => collect($this->as_($u)->getJson('/api/v1/announcements')->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['جلسه اولیا', 'خانواده', 'کل مدرسه'], $titles($this->u1));
        $this->assertSame(['جلسه اولیا', 'خانواده', 'کل مدرسه'], $titles($this->guardian));            // family of s1
        $this->assertSame(['جلسه اولیا', 'کل مدرسه'], $titles($this->u2));
        $this->assertSame(['کل مدرسه'], $titles($this->outsiderUser));
        $this->as_($this->u1)->postJson('/api/v1/announcements', ['title' => 'x', 'body' => 'x', 'audience_type' => 'school'])->assertForbidden();
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'announcement')->where('user_id', $this->guardian->id)->exists());
    }

    public function test_student_notes_visibility(): void
    {
        $this->as_($this->teacher)->postJson("/api/v1/students/{$this->s1->id}/notes", ['kind' => 'praise', 'title' => 'مشارکت عالی', 'visible_to_guardian' => true])->assertCreated();
        $this->postJson("/api/v1/students/{$this->s1->id}/notes", ['kind' => 'discipline', 'title' => 'تأخیر مکرر', 'visible_to_guardian' => false])->assertCreated();
        $this->postJson("/api/v1/students/{$this->s1->id}/notes", ['kind' => 'counselling', 'title' => 'محرمانه'])->assertForbidden();               // counselling: staff only
        $this->as_($this->deputy)->postJson("/api/v1/students/{$this->s1->id}/notes", ['kind' => 'counselling', 'title' => 'جلسه مشاوره'])->assertCreated();

        $this->as_($this->guardian)->getJson("/api/v1/students/{$this->s1->id}/notes")->assertJsonCount(1, 'data');                                  // only the shared praise
        $this->as_($this->deputy)->getJson("/api/v1/students/{$this->s1->id}/notes")->assertJsonCount(3, 'data');
        $this->as_($this->u2)->getJson("/api/v1/students/{$this->s1->id}/notes")->assertNotFound();
        $this->as_($this->u1)->postJson("/api/v1/students/{$this->s1->id}/notes", ['kind' => 'praise', 'title' => 'x'])->assertForbidden();
    }

    public function test_analytics_scoped_to_managers_and_teacher_report(): void
    {
        $this->as_($this->deputy)->getJson('/api/v1/analytics/overview')->assertOk()->assertJsonPath('counts.students', 3);
        $this->getJson('/api/v1/analytics/teachers')->assertOk()->assertJsonPath('data.0.name', 'teacher user');
        $this->as_($this->teacher)->getJson('/api/v1/analytics/overview')->assertForbidden();
        $this->as_($this->u1)->getJson("/api/v1/analytics/students/{$this->s2->id}/progress")->assertNotFound();
        $this->as_($this->guardian)->getJson("/api/v1/analytics/students/{$this->s1->id}/progress")->assertOk();
    }

    // ------------------------------------------------------------------ notifications & realtime
    public function test_dispatch_honours_preferences_is_idempotent_and_reports_unconfigured_push(): void
    {
        \Illuminate\Support\Facades\Queue::fake([]);   // run via explicit dispatcher below
        $this->inSchool($this->school, fn () => app(\App\Modules\Notifications\NotificationService::class)->send([$this->u1->id, $this->u2->id], 'bell.lesson_start', 'k1', 'زنگ'));
        $this->as_($this->u2)->putJson('/api/v1/me/notification-preferences', ['preferences' => [['type' => '*', 'channel' => 'realtime', 'enabled' => false]]])->assertOk();
        DeviceToken::create(['user_id' => $this->u1->id, 'platform' => 'android', 'token' => 'tok1']);

        $d = app(\App\Modules\Notifications\NotificationDispatcher::class);
        $this->inSchool($this->school, fn () => $d->dispatchPending());
        $this->inSchool($this->school, fn () => $d->dispatchPending());      // second run: nothing new

        $n1 = OutboxNotification::withoutGlobalScopes()->where('user_id', $this->u1->id)->first();
        $n2 = OutboxNotification::withoutGlobalScopes()->where('user_id', $this->u2->id)->first();
        $st = fn ($n, $c) => NotificationDelivery::where('notification_id', $n->id)->where('channel', $c)->value('status');
        $this->assertSame('unconfigured', $st($n1, 'push'));        // FCM not configured → recorded honestly, not faked as sent
        $this->assertSame('skipped', $st($n2, 'realtime'));          // user opted out
        $this->assertSame('skipped', $st($n1, 'email'));             // bell is not an email type
        $this->assertNotNull($n1->refresh()->dispatched_at);
        $this->assertSame(6, NotificationDelivery::count());
    }

    public function test_email_channel_for_important_types_and_fcm_delivery_and_stale_token_cleanup(): void
    {
        Mail::fake();
        config(['notifications.fcm.project_id' => 'p', 'notifications.fcm.service_account_json' => $this->fakeServiceAccount()]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']), 'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'ok'])->push(['error' => ['status' => 'UNREGISTERED']], 404)]);
        $this->guardian->forceFill(['email' => 'g@example.test'])->save();
        DeviceToken::create(['user_id' => $this->guardian->id, 'platform' => 'web', 'token' => 'good']);
        DeviceToken::create(['user_id' => $this->guardian->id, 'platform' => 'web', 'token' => 'stale']);

        $this->inSchool($this->school, function () {
            app(\App\Modules\Notifications\NotificationService::class)->send([$this->guardian->id], 'attendance.absent', 'abs1', 'غیبت');
            app(\App\Modules\Notifications\NotificationDispatcher::class)->dispatchPending();
        });
        Mail::assertNothingQueued();
        $this->assertSame(1, DeviceToken::where('user_id', $this->guardian->id)->count());          // stale token removed
        $n = OutboxNotification::withoutGlobalScopes()->where('user_id', $this->guardian->id)->first();
        $this->assertSame('sent', NotificationDelivery::where('notification_id', $n->id)->where('channel', 'push')->value('status'));
        $this->assertSame('sent', NotificationDelivery::where('notification_id', $n->id)->where('channel', 'email')->value('status'));
    }

    private function fakeServiceAccount(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $path = sys_get_temp_dir().'/sa-test.json';
        file_put_contents($path, json_encode(['client_email' => 'sa@p.iam.gserviceaccount.com', 'private_key' => $pem]));

        return $path;
    }

    public function test_device_tokens_move_between_users_on_shared_devices(): void
    {
        $this->as_($this->u1)->postJson('/api/v1/me/devices', ['platform' => 'web', 'token' => 'shared'])->assertCreated();
        $this->as_($this->u2)->postJson('/api/v1/me/devices', ['platform' => 'web', 'token' => 'shared'])->assertCreated();
        $this->assertSame([$this->u2->id], DeviceToken::where('token', 'shared')->pluck('user_id')->all());
        $this->deleteJson('/api/v1/me/devices', ['token' => 'shared'])->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_websocket_channel_authorisation_is_membership_based(): void
    {
        $other = $this->makeSchool('other');
        $sid = $this->school->id;
        $this->assertTrue(ChannelAuth::user($this->u1, $sid, $this->u1->id));
        $this->assertFalse(ChannelAuth::user($this->u1, $sid, $this->u2->id));                    // someone else's private channel
        $this->assertFalse(ChannelAuth::user($this->u1, $other->id, $this->u1->id));              // school I don't belong to

        $session = $this->inSchool($this->school, fn () => \App\Models\LessonSession::create(['section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'teacher_id' => $this->fx['teacher']->id,
            'on_date' => today(), 'title' => 'x', 'scheduled_start' => now(), 'scheduled_end' => now()->addHour(), 'room_name' => 'r1']));
        $this->assertTrue(ChannelAuth::session($this->u1, $sid, $session->id));
        $this->assertTrue(ChannelAuth::session($this->teacher, $sid, $session->id));
        $this->assertFalse(ChannelAuth::session($this->outsiderUser, $sid, $session->id));          // other class
        $this->assertFalse(ChannelAuth::session($this->guardian, $sid, $session->id));

        $conv = $this->inSchool($this->school, fn () => \App\Models\Conversation::create(['type' => 'class', 'section_id' => $this->fx['section']->id]));
        $this->inSchool($this->school, fn () => \App\Models\ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $this->u1->id]));
        $this->assertTrue(ChannelAuth::conversation($this->u1, $sid, $conv->id));
        $this->assertFalse(ChannelAuth::conversation($this->u2, $sid, $conv->id));
    }

    public function test_teacher_sees_only_own_classes_students_and_assignments(): void
    {
        // The teacher teaches section A (s1, s2). Section B (outsider) belongs to someone else.
        $students = $this->as_($this->teacher)->getJson('/api/v1/academics/students')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->s1->id, $this->s2->id], array_column($students, 'id'));
        $this->getJson("/api/v1/academics/students/{$this->outsider->id}")->assertNotFound();
        $this->assertSame([$this->fx['section']->id], array_column($this->getJson('/api/v1/academics/sections')->json('data'), 'id'));
        $this->getJson("/api/v1/academics/sections/{$this->section2->id}")->assertNotFound();
        $this->assertEqualsCanonicalizing([$this->fx['math']->id, $this->fx['sci']->id], array_column($this->getJson('/api/v1/academics/subjects')->json('data'), 'id'));
        $this->getJson('/api/v1/enrollments')->assertJsonCount(2, 'data');

        // another teacher with no assignments sees nothing of this class
        $other = $this->makeMember($this->school, 'teacher');
        $this->inSchool($this->school, fn () => \App\Models\Teacher::create(['user_id' => $other->id]));
        $this->as_($other)->getJson('/api/v1/academics/students')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/teacher-assignments')->assertJsonCount(0, 'data');
        // staff still see everything
        $this->as_($this->deputy)->getJson('/api/v1/academics/students')->assertJsonCount(3, 'data');
    }

    public function test_guardian_meeting_requests_only_for_own_children_and_are_answered_by_the_school(): void
    {
        $teacherModel = $this->fx['teacher'];
        $this->as_($this->guardian)->postJson('/api/v1/meetings', ['student_id' => $this->s2->id, 'topic' => 'پیگیری'])->assertForbidden();        // not my child
        $this->postJson('/api/v1/meetings', ['student_id' => $this->s1->id, 'teacher_id' => $teacherModel->id, 'topic' => 'افت نمره ریاضی'])->assertCreated();
        $this->as_($this->u1)->postJson('/api/v1/meetings', ['student_id' => $this->s1->id, 'topic' => 'x'])->assertForbidden();                  // students can't (and no guardian.access)
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'meeting.requested')->where('user_id', $this->teacher->id)->exists());

        $id = $this->as_($this->teacher)->getJson('/api/v1/meetings')->assertJsonCount(1, 'data')->json('data.0.id');
        $other = $this->makeMember($this->school, 'teacher');
        $this->inSchool($this->school, fn () => \App\Models\Teacher::create(['user_id' => $other->id]));
        $this->as_($other)->getJson('/api/v1/meetings')->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/meetings/$id/respond", ['status' => 'accepted', 'scheduled_at' => now()->addDays(2)->toIso8601String()])->assertForbidden();
        $this->as_($this->teacher)->putJson("/api/v1/meetings/$id/respond", ['status' => 'accepted'])->assertStatus(422);                           // needs a date
        $this->putJson("/api/v1/meetings/$id/respond", ['status' => 'accepted', 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'response_note' => 'سه‌شنبه'])->assertOk();
        $this->as_($this->guardian)->getJson('/api/v1/meetings')->assertJsonPath('data.0.status', 'accepted');
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'meeting.responded')->where('user_id', $this->guardian->id)->exists());
    }
}

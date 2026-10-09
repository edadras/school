<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\LessonSession;
use App\Models\OutboxNotification;
use App\Models\SessionParticipant;
use App\Modules\Scheduling\BellEngine;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use App\Modules\VirtualClassrooms\Media\NullMediaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeMediaProvider;
use Tests\Support\World;
use Tests\TestCase;

class LiveClassAndAttendanceTest extends TestCase
{
    use RefreshDatabase, World;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMediaProvider::$calls = [];
        FakeMediaProvider::$configured = true;
        $this->app->bind(MediaProvider::class, FakeMediaProvider::class);
        $this->buildWorld();
    }

    private function makeup(array $over = []): int
    {
        return $this->as_($this->teacher)->postJson('/api/v1/sessions', $over + [
            'section_id' => $this->fx['section']->id, 'subject_id' => $this->fx['math']->id, 'kind' => 'makeup', 'title' => 'کلاس جبرانی ریاضی',
            'scheduled_start' => now()->addMinutes(5)->toIso8601String(), 'scheduled_end' => now()->addMinutes(50)->toIso8601String(),
        ])->assertCreated()->json('data.id');
    }

    public function test_makeup_class_is_announced_and_only_the_host_can_start_it(): void
    {
        $id = $this->makeup();
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'session.scheduled')->where('user_id', $this->u1->id)->exists());
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'session.scheduled')->where('user_id', $this->guardian->id)->exists());

        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/start")->assertForbidden();
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertStatus(422);               // not live yet
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start")->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertSame('createRoom', FakeMediaProvider::$calls[0][0]);
    }

    public function test_join_issues_scoped_tokens_and_blocks_outsiders(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start")->assertOk();

        $host = $this->postJson("/api/v1/sessions/$id/join")->assertOk()->json();
        $this->assertSame('host', $host['role']);
        $stu = $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertOk()->json();
        $this->assertSame(['participant', 'wss://sfu.test'], [$stu['role'], $stu['url']]);

        $this->as_($this->outsiderUser)->postJson("/api/v1/sessions/$id/join")->assertNotFound();      // other class: not even visible
        $this->as_($this->guardian)->postJson("/api/v1/sessions/$id/join")->assertForbidden();         // parents don't join lessons
        $this->as_($this->deputy)->postJson("/api/v1/sessions/$id/join")->assertOk()->assertJsonPath('role', 'observer');   // managers observe receive-only
        $this->as_($this->admin)->postJson("/api/v1/sessions/$id/join")->assertOk()->assertJsonPath('role', 'observer');
        $this->as_($this->admin)->postJson("/api/v1/sessions/$id/start")->assertForbidden();                    // managers cannot take over a class
        $this->as_($this->deputy)->putJson("/api/v1/sessions/$id/participants/{$this->u1->id}/mute")->assertForbidden();
    }

    public function test_attendance_is_recorded_from_joins_and_closed_as_absent(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 04:59:00', 'UTC'));
        $id = $this->makeup(['scheduled_start' => '2026-10-10 05:00:00', 'scheduled_end' => '2026-10-10 05:45:00']);
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start")->assertOk();
        $this->travelTo(Carbon::parse('2026-10-10 05:00:30', 'UTC'));

        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertOk();                      // on time → present
        $this->travelTo(Carbon::parse('2026-10-10 05:12:00', 'UTC'));
        $this->as_($this->u2)->postJson("/api/v1/sessions/$id/join")->assertOk();                      // 12 min late → late

        $r1 = AttendanceRecord::withoutGlobalScopes()->where('student_id', $this->s1->id)->first();
        $r2 = AttendanceRecord::withoutGlobalScopes()->where('student_id', $this->s2->id)->first();
        $this->assertSame(['present', 0], [$r1->status, $r1->minutes_late]);
        $this->assertSame(['late', 12], [$r2->status, $r2->minutes_late]);

        // rejoin does not change anything; ending the class never creates records for those who attended
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertOk();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/end")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->assertSame(2, AttendanceRecord::withoutGlobalScopes()->count());
        $this->assertSame(0, SessionParticipant::withoutGlobalScopes()->whereNull('left_at')->count());
        $this->assertContains('endRoom', array_column(FakeMediaProvider::$calls, 0));
    }

    public function test_absent_students_are_closed_and_guardians_notified_once(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start");
        $this->as_($this->u2)->postJson("/api/v1/sessions/$id/join")->assertOk();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/end")->assertOk();
        $this->postJson("/api/v1/sessions/$id/end")->assertOk();                                          // idempotent

        $abs = AttendanceRecord::withoutGlobalScopes()->where('student_id', $this->s1->id)->first();
        $this->assertSame('absent', $abs->status);
        $this->assertSame(1, OutboxNotification::withoutGlobalScopes()->where('type', 'attendance.absent')->where('user_id', $this->guardian->id)->count());
        // guardian sees the child's attendance, not others
        $this->as_($this->guardian)->getJson('/api/v1/attendance')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/attendance/students/{$this->s2->id}/summary")->assertNotFound();
        $this->getJson("/api/v1/attendance/students/{$this->s1->id}/summary")->assertOk()->assertJsonPath('data.absent', 1);
    }

    public function test_teacher_can_correct_attendance_and_automation_will_not_overwrite_it(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start");
        $this->putJson("/api/v1/sessions/$id/attendance", ['items' => [['student_id' => $this->s1->id, 'status' => 'excused', 'note' => 'مریض'], ['student_id' => $this->outsider->id, 'status' => 'absent']]])
            ->assertOk()->assertJsonPath('updated', 1);                                                    // outsider ignored
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertOk();
        $this->assertSame('excused', AttendanceRecord::withoutGlobalScopes()->where('student_id', $this->s1->id)->value('status'));
        $this->as_($this->u1)->putJson("/api/v1/sessions/$id/attendance", ['items' => [['student_id' => $this->s1->id, 'status' => 'present']]])->assertForbidden();
    }

    public function test_hand_raise_queue_mute_kick_and_whiteboard(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start");
        $this->as_($this->u1)->putJson("/api/v1/sessions/$id/hand", ['raise' => true])->assertForbidden();   // must have joined
        $this->postJson("/api/v1/sessions/$id/join");
        $this->putJson("/api/v1/sessions/$id/hand", ['raise' => true])->assertNoContent();
        $this->as_($this->u2)->postJson("/api/v1/sessions/$id/join");
        $this->putJson("/api/v1/sessions/$id/hand", ['raise' => true])->assertNoContent();

        $show = $this->as_($this->teacher)->getJson("/api/v1/sessions/$id")->json();
        $this->assertSame([$this->u1->id, $this->u2->id], array_column($show['hands'], 'user_id'));        // speaking order
        $this->as_($this->u1)->getJson("/api/v1/sessions/$id")->assertJsonPath('hands', []);              // students can't see the queue

        $this->as_($this->u1)->putJson("/api/v1/sessions/$id/participants/{$this->u2->id}/mute")->assertForbidden();
        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/participants/{$this->u2->id}/mute", ['muted' => true])->assertNoContent();
        $this->deleteJson("/api/v1/sessions/$id/participants/{$this->u2->id}")->assertNoContent();
        $calls = array_column(FakeMediaProvider::$calls, 0);
        $this->assertContains('mute', $calls);
        $this->assertContains('remove', $calls);
        $this->assertSame(0, SessionParticipant::withoutGlobalScopes()->where('user_id', $this->u2->id)->whereNull('left_at')->count());

        // whiteboard: host writes, students replay
        $this->postJson("/api/v1/sessions/$id/whiteboard", ['type' => 'stroke', 'data' => ['pts' => [[0, 0], [5, 5]]]])->assertCreated();
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/whiteboard", ['type' => 'stroke', 'data' => []])->assertForbidden();
        $this->getJson("/api/v1/sessions/$id/whiteboard")->assertOk()->assertJsonCount(1, 'data');
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/whiteboard", ['type' => 'clear']);
        $this->getJson("/api/v1/sessions/$id/whiteboard")->assertJsonCount(1, 'data');                      // only the clear marker remains
    }

    public function test_concurrent_live_class_limit_from_subscription(): void
    {
        \App\Models\SchoolSubscription::where('school_id', $this->school->id)->update(['max_live_sessions' => 1]);
        $a = $this->makeup();
        $b = $this->makeup(['title' => 'دوم']);
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$a/start")->assertOk();
        $this->postJson("/api/v1/sessions/$b/start")->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_unconfigured_media_is_reported_not_faked(): void
    {
        $this->app->bind(MediaProvider::class, NullMediaProvider::class);
        $id = $this->makeup();
        $this->as_($this->teacher)->getJson('/api/v1/media/status')->assertJsonPath('configured', false);
        $this->postJson("/api/v1/sessions/$id/start")->assertStatus(503)->assertJsonPath('code', 'media_unconfigured');
        $this->assertSame('scheduled', LessonSession::withoutGlobalScopes()->find($id)->status);
    }

    public function test_bell_creates_session_and_marks_unstarted_class_not_held_and_reports(): void
    {
        [$tt, $periods] = $this->timetable();
        $engine = app(BellEngine::class);
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:30:30', 'UTC'));       // 08:00:30 Tehran Saturday
        $s = LessonSession::withoutGlobalScopes()->first();
        $this->assertSame('scheduled', $s->status);
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->get()->every(fn ($n) => $n->data['session_id'] === $s->id));

        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:16:30', 'UTC'));       // after 08:45 Tehran
        $this->assertSame('not_held', $s->refresh()->status);
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'session.not_held')->where('user_id', $this->admin->id)->exists());
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:17:30', 'UTC'));       // re-run: nothing duplicated
        $this->assertSame(1, OutboxNotification::withoutGlobalScopes()->where('type', 'session.not_held')->where('user_id', $this->admin->id)->count());
    }

    public function test_live_class_left_running_is_closed_automatically(): void
    {
        [$tt, $periods] = $this->timetable();
        $engine = app(BellEngine::class);
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:30:30', 'UTC'));
        $s = LessonSession::withoutGlobalScopes()->first();
        $this->travelTo(Carbon::parse('2026-10-10 04:31:00', 'UTC'));
        $this->as_($this->teacher)->postJson("/api/v1/sessions/{$s->id}/start")->assertOk();
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:10:00', 'UTC'));       // 08:40 + 25 min over? (end 05:15 UTC) → still live
        $this->assertSame('live', $s->refresh()->status);
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:40:00', 'UTC'));
        $this->assertSame('ended', $s->refresh()->status);
        $this->assertSame(2, AttendanceRecord::withoutGlobalScopes()->where('status', 'absent')->count());
    }

    public function test_webhook_requires_valid_signature_and_updates_presence(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start");
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join");
        $room = LessonSession::withoutGlobalScopes()->find($id)->room_name;
        $body = ['event' => 'participant_left', 'room' => $room, 'identity' => 'u'.$this->u1->id];

        $this->postJson('/api/v1/webhooks/livekit', $body)->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/livekit', $body, ['Authorization' => 'valid'])->assertOk();
        $this->assertSame(0, SessionParticipant::withoutGlobalScopes()->where('user_id', $this->u1->id)->whereNull('left_at')->count());
        $this->postJson('/api/v1/webhooks/livekit', ['event' => 'room_finished', 'room' => $room], ['Authorization' => 'valid'])->assertOk();
        $this->assertSame('ended', LessonSession::withoutGlobalScopes()->find($id)->status);
    }

    public function test_issue_reports_and_management_summary(): void
    {
        $id = $this->makeup();
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/issues", ['type' => 'audio', 'note' => 'صدا قطع است'])->assertCreated();
        $this->as_($this->admin)->getJson('/api/v1/sessions/report')->assertOk()->assertJsonPath('issues.audio', 1);
        $this->as_($this->u1)->getJson('/api/v1/sessions/report')->assertForbidden();
    }

    public function test_recording_requires_school_policy_and_is_announced_to_joiners(): void
    {
        $id = $this->makeup();
        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertStatus(422);       // policy off by default
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['recording.allowed' => true]])->assertOk();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start");
        $this->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertOk()->assertJsonPath('data.recording_enabled', true);
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertJsonPath('recording', true);                      // joiners are told
    }

    private function liveWithRecording(): int
    {
        FakeMediaProvider::$recording = true;
        $id = $this->makeup();
        $this->as_($this->admin)->patchJson('/api/v1/school/settings', ['settings' => ['recording.allowed' => true]])->assertOk();
        $this->as_($this->teacher)->postJson("/api/v1/sessions/$id/start")->assertOk();

        return $id;
    }

    public function test_recording_without_egress_reports_unconfigured_and_starts_nothing(): void
    {
        $id = $this->liveWithRecording();
        FakeMediaProvider::$recording = false;
        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertStatus(503)->assertJsonPath('code', 'recording_unconfigured');
        $this->assertSame([], array_filter(FakeMediaProvider::$calls, fn ($c) => $c[0] === 'startRecording'));
        $this->assertSame(0, \App\Models\SessionRecording::withoutGlobalScopes()->count());
    }

    public function test_recording_lifecycle_start_stop_webhook_and_access_control(): void
    {
        $id = $this->liveWithRecording();
        $this->as_($this->u1)->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertForbidden();           // students cannot
        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertOk();
        $this->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertOk();                                   // idempotent
        $this->assertCount(1, array_filter(FakeMediaProvider::$calls, fn ($c) => $c[0] === 'startRecording'));
        $rec = \App\Models\SessionRecording::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('recording', $rec->status);
        $this->assertStringStartsWith('schools/'.$this->school->id.'/recordings/', $rec->object_key);
        $this->as_($this->u1)->postJson("/api/v1/sessions/$id/join")->assertJsonPath('recording', true);                      // joiners are told

        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/recording", ['enabled' => false])->assertOk()->assertJsonPath('data.recording_enabled', false);
        $this->assertSame('stopping', $rec->fresh()->status);

        // egress finished: signed webhook attaches the object as a private file; replays change nothing
        $payload = json_encode(['id' => $rec->provider_id, 'status' => 'EGRESS_COMPLETE', 'file' => ['size' => 1234, 'duration' => 62000000000]]);
        $this->call('POST', '/api/v1/webhooks/livekit', [], [], [], ['HTTP_AUTHORIZATION' => 'egress', 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $this->call('POST', '/api/v1/webhooks/livekit', [], [], [], ['HTTP_AUTHORIZATION' => 'egress', 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        $rec->refresh();
        $this->assertSame(['ready', 1234, 62], [$rec->status, $rec->size, $rec->duration_seconds]);
        $this->assertSame(1, \App\Models\StoredFile::withoutGlobalScopes()->where('context_type', 'recording')->count());

        // who may see it
        $this->as_($this->teacher)->getJson("/api/v1/sessions/$id/recordings")->assertOk()->assertJsonPath('data.0.status', 'ready');
        $this->as_($this->admin)->getJson("/api/v1/sessions/$id/recordings")->assertOk();
        $this->as_($this->u1)->getJson("/api/v1/sessions/$id/recordings")->assertForbidden();
        $this->as_($this->guardian)->getJson("/api/v1/sessions/$id/recordings")->assertForbidden();
        $fileId = $rec->file_id;
        $this->as_($this->teacher)->getJson("/api/v1/files/$fileId/link")->assertOk();
        $this->as_($this->admin)->getJson("/api/v1/files/$fileId/link")->assertOk();
        $this->as_($this->u1)->getJson("/api/v1/files/$fileId/link")->assertStatus(404);                                       // students never get the link
    }

    public function test_ending_the_class_stops_a_running_recording(): void
    {
        $id = $this->liveWithRecording();
        $this->as_($this->teacher)->putJson("/api/v1/sessions/$id/recording", ['enabled' => true])->assertOk();
        $this->postJson("/api/v1/sessions/$id/end")->assertOk();
        $this->assertNotEmpty(array_filter(FakeMediaProvider::$calls, fn ($c) => $c[0] === 'stopRecording'));
        $this->assertSame('stopping', \App\Models\SessionRecording::withoutGlobalScopes()->first()->status);
    }

    private function timetable(): array
    {
        $r = $this->as_($this->admin)->postJson('/api/v1/timetables', [
            'academic_year_id' => $this->fx['year']->id, 'title' => 'ت', 'working_days' => [0, 1, 2, 3, 4],
            'periods' => [['kind' => 'lesson', 'title' => 'زنگ اول', 'starts_at' => '08:00', 'ends_at' => '08:45']],
        ])->assertCreated();
        $id = $r->json('data.id');
        $this->postJson("/api/v1/timetables/$id/entries", ['period_id' => $r->json('data.periods.0.id'), 'weekday' => 0, 'section_id' => $this->fx['section']->id,
            'subject_id' => $this->fx['math']->id, 'teacher_id' => $this->fx['teacher']->id])->assertCreated();
        $this->postJson("/api/v1/timetables/$id/activate")->assertOk();

        return [$id, $r->json('data.periods')];
    }
}

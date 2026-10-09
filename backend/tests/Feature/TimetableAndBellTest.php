<?php

namespace Tests\Feature;

use App\Models\OutboxNotification;
use App\Models\ScheduleEvent;
use App\Models\Student;
use App\Models\Enrollment;
use App\Models\Timetable;
use App\Modules\Scheduling\BellEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class TimetableAndBellTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    private $school;
    private $admin;
    private array $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->school = $this->makeSchool('a');
        $this->admin = $this->makeMember($this->school, 'school_admin');
        $this->fx = $this->academicFixture($this->school);
    }

    private function createTimetable(): array
    {
        $r = $this->as($this->admin, $this->school)->postJson('/api/v1/timetables', [
            'academic_year_id' => $this->fx['year']->id, 'title' => 'ترم اول', 'working_days' => [0, 1, 2, 3, 4],
            'periods' => [
                ['kind' => 'lesson', 'title' => 'زنگ اول', 'starts_at' => '08:00', 'ends_at' => '08:45'],
                ['kind' => 'break', 'title' => 'تفریح اول', 'starts_at' => '08:45', 'ends_at' => '08:55'],
                ['kind' => 'lesson', 'title' => 'زنگ دوم', 'starts_at' => '08:55', 'ends_at' => '09:40'],
            ],
        ])->assertCreated();

        return [$r->json('data.id'), $r->json('data.periods')];
    }

    private function entry(int $period, int $weekday, ?int $subject = null, ?int $teacher = null): array
    {
        return ['period_id' => $period, 'weekday' => $weekday, 'section_id' => $this->fx['section']->id,
            'subject_id' => $subject ?? $this->fx['math']->id, 'teacher_id' => $teacher ?? $this->fx['teacher']->id];
    }

    /** A student with a login account in the fixture section. */
    private function studentUser(): \App\Models\User
    {
        $u = $this->makeMember($this->school, 'student');
        $this->inSchool($this->school, function () use ($u) {
            $st = Student::create(['user_id' => $u->id, 'first_name' => 'د', 'last_name' => 'آ', 'student_code' => uniqid()]);
            Enrollment::create(['student_id' => $st->id, 'section_id' => $this->fx['section']->id, 'academic_year_id' => $this->fx['year']->id]);
        });

        return $u;
    }

    public function test_overlapping_or_inverted_periods_are_rejected(): void
    {
        $this->as($this->admin, $this->school)->postJson('/api/v1/timetables', [
            'academic_year_id' => $this->fx['year']->id, 'title' => 'x', 'working_days' => [0],
            'periods' => [
                ['kind' => 'lesson', 'title' => 'a', 'starts_at' => '08:00', 'ends_at' => '08:45'],
                ['kind' => 'lesson', 'title' => 'b', 'starts_at' => '08:30', 'ends_at' => '09:00'],
            ],
        ])->assertStatus(422);
    }

    public function test_conflicts_are_detected_before_saving(): void
    {
        [$id, $periods] = $this->createTimetable();
        $p1 = $periods[0]['id'];
        $p2 = $periods[2]['id'];

        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($p1, 0))->assertCreated();

        // same class, same slot, other subject => section busy (+ teacher busy as same teacher)
        $this->postJson("/api/v1/timetables/$id/check", $this->entry($p1, 0, $this->fx['sci']->id))
            ->assertOk()->assertJsonPath('ok', false)
            ->assertJsonFragment(['code' => 'section_busy'])->assertJsonFragment(['code' => 'teacher_busy']);
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($p1, 0, $this->fx['sci']->id))->assertStatus(422);

        // break period is not bookable; non-working day (Friday=6) is rejected
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[1]['id'], 0))->assertStatus(422);
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($p2, 6))->assertStatus(422);

        // teacher that is not the assigned one
        $other = $this->inSchool($this->school, fn () => \App\Models\Teacher::create(['user_id' => $this->makeMember($this->school, 'teacher')->id]));
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($p2, 0, null, $other->id))->assertStatus(422)->assertJsonFragment(['conflicts' => ['معلم انتخابی با معلم تخصیص‌یافته به این درس یکی نیست.']]);
    }

    public function test_teacher_cannot_teach_two_sections_at_once(): void
    {
        [$id, $periods] = $this->createTimetable();
        $sec2 = $this->inSchool($this->school, function () {
            $s = \App\Models\Section::create(['grade_id' => $this->fx['grade']->id, 'academic_year_id' => $this->fx['year']->id, 'name' => 'ب']);
            \App\Models\TeacherAssignment::create(['teacher_id' => $this->fx['teacher']->id, 'section_id' => $s->id, 'subject_id' => $this->fx['math']->id]);

            return $s;
        });
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0))->assertCreated();
        $this->postJson("/api/v1/timetables/$id/entries", ['section_id' => $sec2->id] + $this->entry($periods[0]['id'], 0))
            ->assertStatus(422)->assertJsonFragment(['conflicts' => ['این معلم در این زنگ در کلاس دیگری تدریس می‌کند.']]);
    }

    public function test_activation_archives_previous_version_and_notifies_once(): void
    {
        $stu = $this->studentUser();
        [$id1, $p1] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id1/entries", $this->entry($p1[0]['id'], 0))->assertCreated();
        $this->postJson("/api/v1/timetables/$id1/activate")->assertOk();
        [$id2, $p2] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id2/entries", $this->entry($p2[0]['id'], 1))->assertCreated();
        $this->postJson("/api/v1/timetables/$id2/activate")->assertOk();

        $this->assertSame('archived', Timetable::withoutGlobalScopes()->find($id1)->status); // history retained
        $this->assertSame(2, Timetable::withoutGlobalScopes()->find($id2)->version);
        $this->as($stu, $this->school)->getJson('/api/v1/me/notifications')->assertJsonCount(2, 'data'); // one per activation
        $this->as($this->admin, $this->school)->postJson("/api/v1/timetables/$id2/activate")->assertOk();
        $this->as($stu, $this->school)->getJson('/api/v1/me/notifications')->assertJsonCount(2, 'data'); // re-activation: no duplicate
    }

    public function test_bell_fires_on_server_time_in_school_timezone_and_is_idempotent(): void
    {
        $stu = $this->studentUser();
        [$id, $periods] = $this->createTimetable();
        // 2026-10-10 is a Saturday => weekday 0
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0))->assertCreated();
        $this->postJson("/api/v1/timetables/$id/activate");
        $engine = app(BellEngine::class);

        // 07:59 Tehran (04:29 UTC): nothing due.
        $this->assertSame(0, $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:29:00', 'UTC')));
        $this->assertSame(6, $this->inSchool($this->school, fn () => ScheduleEvent::count())); // 3 periods x start/end

        // 08:00:30 Tehran (04:30:30 UTC): lesson start fires.
        $at = Carbon::parse('2026-10-10 04:30:30', 'UTC');
        $this->assertSame(1, $engine->runForSchool($this->school, $at));
        $first = OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->count();
        $this->assertSame(2, $first); // student + teacher

        // Re-run (overlapping scheduler, retried job): nothing new, nothing duplicated.
        $this->assertSame(0, $engine->runForSchool($this->school, $at));
        // Even if processed_at were lost (crash between delivery and marking), dedupe keys hold.
        ScheduleEvent::withoutGlobalScopes()->update(['processed_at' => null]);
        $engine->runForSchool($this->school, $at);
        $this->assertSame($first, OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->count());

        // Student's feed shows the bell with server time for clock alignment.
        $this->as($stu, $this->school)->getJson('/api/v1/me/notifications')->assertJsonFragment(['type' => 'bell.lesson_start'])->assertJsonStructure(['server_time']);
    }

    public function test_unstarted_class_becomes_not_held_and_a_failing_media_server_does_not_block_the_bell(): void
    {
        $this->app->bind(\App\Modules\VirtualClassrooms\Media\MediaProvider::class, \Tests\Support\FakeMediaProvider::class);
        [$id, $periods] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0));
        $this->postJson("/api/v1/timetables/$id/activate");
        $engine = app(BellEngine::class);
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:30:30', 'UTC'));     // 08:00 start: session scheduled

        \Tests\Support\FakeMediaProvider::$failEndRoom = true;
        try {
            // 08:45 end while the SFU is failing: the event stays pending (retried), the engine does not blow up.
            $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:16:00', 'UTC'));
            $this->assertSame('scheduled', $this->inSchool($this->school, fn () => \App\Models\LessonSession::first()->status));
            $this->assertSame(1, ScheduleEvent::withoutGlobalScopes()->whereNull('processed_at')->where('event', 'end')->where('fires_at', '<=', '2026-10-10 05:16:00')->count());
        } finally {
            \Tests\Support\FakeMediaProvider::$failEndRoom = false;
        }

        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 05:17:00', 'UTC'));    // SFU back: closed as not held
        $this->assertSame('not_held', $this->inSchool($this->school, fn () => \App\Models\LessonSession::first()->status));
        $this->assertSame(1, OutboxNotification::withoutGlobalScopes()->where('type', 'session.not_held')->count());
    }

    public function test_restart_catches_up_recent_events_but_skips_stale_ones(): void
    {
        [$id, $periods] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0));
        $this->postJson("/api/v1/timetables/$id/activate");
        $engine = app(BellEngine::class);

        // server was down 08:00..: comes back at 08:03 => start still delivered
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:33:00', 'UTC'));
        $this->assertSame(1, OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->count()); // the teacher

        // comes back at 12:00 Tehran: the remaining old events are closed silently, no stale bells
        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 08:30:00', 'UTC'));
        $this->assertSame(0, ScheduleEvent::withoutGlobalScopes()->whereNull('processed_at')->where('fires_at', '<=', '2026-10-10 08:30:00')->count());
        $this->assertSame(0, OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_end')->count());
    }

    public function test_holiday_and_non_working_day_produce_no_events(): void
    {
        [$id, $periods] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0));
        $this->postJson("/api/v1/timetables/$id/activate");
        $this->postJson('/api/v1/academics/calendar-events', ['type' => 'holiday', 'title' => 'تعطیل', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-10', 'cancels_classes' => true])->assertCreated();
        $engine = app(BellEngine::class);

        $engine->runForSchool($this->school, Carbon::parse('2026-10-10 04:31:00', 'UTC'));   // holiday Saturday
        $engine->runForSchool($this->school, Carbon::parse('2026-10-09 04:31:00', 'UTC'));   // Friday: not a working day
        $this->assertSame(0, ScheduleEvent::withoutGlobalScopes()->count());
    }

    public function test_substitute_gets_the_bell_and_cancelled_lesson_stays_silent(): void
    {
        $this->studentUser();
        [$id, $periods] = $this->createTimetable();
        $entryId = $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0))->json('data.id');
        $this->postJson("/api/v1/timetables/$id/activate");
        $subUser = $this->makeMember($this->school, 'teacher');
        $sub = $this->inSchool($this->school, fn () => \App\Models\Teacher::create(['user_id' => $subUser->id]));

        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'UTC'));
        $this->postJson('/api/v1/substitutions', ['timetable_entry_id' => $entryId, 'on_date' => '2026-10-10', 'status' => 'substitute', 'substitute_teacher_id' => $sub->id])->assertCreated();
        $this->postJson('/api/v1/substitutions', ['timetable_entry_id' => $entryId, 'on_date' => '2026-10-11', 'status' => 'cancelled'])->assertStatus(422); // wrong weekday

        app(BellEngine::class)->runForSchool($this->school, Carbon::parse('2026-10-10 04:30:30', 'UTC'));
        $this->assertTrue(OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->where('user_id', $subUser->id)->exists());
        $this->assertFalse(OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->where('user_id', $this->fx['tUser']->id)->exists());

        // cancel instead
        $this->postJson('/api/v1/substitutions', ['timetable_entry_id' => $entryId, 'on_date' => '2026-10-10', 'status' => 'cancelled'])->assertCreated();
        OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->delete();
        ScheduleEvent::withoutGlobalScopes()->update(['processed_at' => null]);
        app(BellEngine::class)->runForSchool($this->school, Carbon::parse('2026-10-10 04:30:40', 'UTC'));
        $this->assertSame(0, OutboxNotification::withoutGlobalScopes()->where('type', 'bell.lesson_start')->count());
    }

    public function test_my_schedule_for_student_and_teacher_is_limited_to_their_own_entries(): void
    {
        $stu = $this->studentUser();
        [$id, $periods] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0));
        $this->postJson("/api/v1/timetables/$id/activate");

        $this->as($stu, $this->school)->getJson('/api/v1/me/schedule')->assertOk()->assertJsonCount(1, 'entries');
        $this->as($this->fx['tUser'], $this->school)->getJson('/api/v1/me/schedule')->assertOk()->assertJsonCount(1, 'entries');
        $outsider = $this->makeMember($this->school, 'student');
        $this->as($outsider, $this->school)->getJson('/api/v1/me/schedule')->assertOk()->assertJsonCount(0, 'entries');
        $this->getJson("/api/v1/timetables/$id")->assertForbidden(); // students can't read the full timetable
    }

    public function test_bell_tick_command_runs_across_schools_without_cross_contamination(): void
    {
        $b = $this->makeSchool('b');
        $this->makeMember($b, 'teacher');
        [$id, $periods] = $this->createTimetable();
        $this->postJson("/api/v1/timetables/$id/entries", $this->entry($periods[0]['id'], 0));
        $this->postJson("/api/v1/timetables/$id/activate");

        $this->travelTo(Carbon::parse('2026-10-10 04:30:30', 'UTC'));
        $this->artisan('bell:tick')->assertSuccessful();
        $this->assertSame(0, OutboxNotification::withoutGlobalScopes()->where('school_id', $b->id)->count());
        $this->assertGreaterThan(0, OutboxNotification::withoutGlobalScopes()->where('school_id', $this->school->id)->where('type', 'bell.lesson_start')->count());
    }
}

<?php

namespace App\Modules\Scheduling;

use App\Models\School;
use App\Models\ScheduleEvent;
use App\Models\Substitution;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetablePeriod;
use App\Modules\Notifications\NotificationService;
use App\Modules\Tenancy\CurrentSchool;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server-time bell engine. Two idempotent steps, both safe to run repeatedly / concurrently:
 *   1. generate(): materialise today's start/end events (unique key => insertOrIgnore).
 *   2. tick():     fire due events. Notifications are keyed by (user, event) so a re-run or an
 *                  overlapping worker cannot duplicate them; processed_at is set only after delivery.
 * After a restart, events missed within CATCH_UP_MINUTES are still delivered; older ones are
 * marked processed silently (a stale "class starts now" would be misleading).
 */
class BellEngine
{
    public const CATCH_UP_MINUTES = 10;

    public function __construct(private NotificationService $notify, private CurrentSchool $current) {}

    /** Convert our weekday (0 = Saturday) for a date in the school's timezone. */
    public static function weekdayOf(CarbonInterface $localDate): int
    {
        return ($localDate->dayOfWeek + 1) % 7;
    }

    public function runForSchool(School $school, ?CarbonInterface $now = null): int
    {
        $now = $now ? Carbon::instance($now) : now();

        return $this->current->run($school, function () use ($school, $now) {
            $this->generate($school, $now);

            return $this->tick($school, $now);
        });
    }

    public function generate(School $school, CarbonInterface $now): int
    {
        $local = Carbon::instance($now)->setTimezone($school->timezone);
        $tt = Timetable::where('status', 'active')->first();

        if (! $tt || ! in_array(self::weekdayOf($local), $tt->working_days, true) || $this->isClassCancelled($local)) {
            return 0;
        }
        $date = $local->toDateString();
        $stamp = now();
        $rows = [];

        foreach ($tt->periods as $p) {
            foreach (['start' => $p->starts_at, 'end' => $p->ends_at] as $event => $time) {
                $rows[] = [
                    'school_id' => $school->id, 'timetable_id' => $tt->id, 'period_id' => $p->id, 'on_date' => $date, 'event' => $event,
                    'fires_at' => Carbon::parse("$date $time", $school->timezone)->utc(), 'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }

        return DB::table('schedule_events')->insertOrIgnore($rows);
    }

    public function tick(School $school, CarbonInterface $now): int
    {
        $fired = 0;
        $due = ScheduleEvent::whereNull('processed_at')->where('fires_at', '<=', $now)->orderBy('fires_at')->limit(200)->get();

        foreach ($due as $event) {
            $stale = $event->fires_at->lt(Carbon::instance($now)->subMinutes(self::CATCH_UP_MINUTES));

            $count = $stale ? 0 : $this->deliver($school, $event);

            // Conditional update: only the first worker to finish records it; others no-op.
            ScheduleEvent::whereKey($event->id)->whereNull('processed_at')
                ->update(['processed_at' => now(), 'notified_count' => $count]);
            $fired++;
        }

        return $fired;
    }

    private function deliver(School $school, ScheduleEvent $event): int
    {
        $period = TimetablePeriod::find($event->period_id);
        $date = $event->on_date->toDateString();
        $weekday = self::weekdayOf($event->on_date);
        $isStart = $event->event === 'start';

        if ($period->kind === 'lesson') {
            $entries = TimetableEntry::where('timetable_id', $event->timetable_id)->where('period_id', $period->id)->where('weekday', $weekday)->get();
            $subs = Substitution::where('on_date', $date)->whereIn('timetable_entry_id', $entries->pluck('id'))->get()->keyBy('timetable_entry_id');
            $sent = 0;

            foreach ($entries as $entry) {
                $sub = $subs[$entry->id] ?? null;
                if ($sub?->status === 'cancelled') {
                    continue; // cancelled lessons produce no bell
                }
                $teacherId = $sub?->substitute_teacher_id ?? $entry->teacher_id;
                $users = collect(Recipients::forSection($school->id, $entry->section_id))->push(Recipients::teacherUser($teacherId))->filter();
                $subject = DB::table('subjects')->where('id', $entry->subject_id)->value('name');

                $sent += $this->notify->send(
                    $users, $isStart ? 'bell.lesson_start' : 'bell.lesson_end', "bell:{$event->id}:{$entry->id}",
                    $isStart ? "شروع {$period->title}: {$subject}" : "پایان {$period->title}: {$subject}", null,
                    ['period_id' => $period->id, 'entry_id' => $entry->id, 'section_id' => $entry->section_id, 'fires_at' => $event->fires_at->toIso8601String()],
                );
            }

            return $sent;
        }

        // Break / prep: everyone who has a lesson today.
        if (! $isStart) {
            return 0;
        }
        $teacherIds = TimetableEntry::where('timetable_id', $event->timetable_id)->where('weekday', $weekday)->distinct()->pluck('teacher_id');
        $users = $teacherIds->map(fn ($id) => Recipients::teacherUser($id));
        foreach (TimetableEntry::where('timetable_id', $event->timetable_id)->where('weekday', $weekday)->distinct()->pluck('section_id') as $sid) {
            $users = $users->merge(Recipients::forSection($school->id, $sid));
        }

        return $this->notify->send($users->filter(), 'bell.break_start', "bell:{$event->id}", $period->title, null, ['period_id' => $period->id]);
    }

    private function isClassCancelled(CarbonInterface $local): bool
    {
        $d = $local->toDateString();

        return DB::table('school_calendar_events')->where('school_id', $this->current->id())
            ->where('cancels_classes', true)->whereDate('starts_on', '<=', $d)->whereDate('ends_on', '>=', $d)->exists();
    }
}

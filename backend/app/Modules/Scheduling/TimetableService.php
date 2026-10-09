<?php

namespace App\Modules\Scheduling;

use App\Models\Substitution;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetablePeriod;
use App\Modules\Audit\Audit;
use App\Modules\Notifications\NotificationService;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimetableService
{
    public function __construct(private NotificationService $notify) {}

    /** @param list<array{kind:string,title:string,starts_at:string,ends_at:string}> $periods */
    public function create(array $attrs, array $periods): Timetable
    {
        $this->assertPeriodsValid($periods);

        return DB::transaction(function () use ($attrs, $periods) {
            $version = (int) Timetable::where('academic_year_id', $attrs['academic_year_id'])->max('version') + 1;
            $tt = Timetable::create($attrs + ['version' => $version, 'status' => 'draft']);

            foreach (array_values($periods) as $i => $p) {
                TimetablePeriod::create($p + ['timetable_id' => $tt->id, 'position' => $i + 1]);
            }
            Audit::record('timetable.created', $tt, null, ['version' => $version]);

            return $tt->load('periods');
        });
    }

    /** Periods must be ordered, non-overlapping and well-formed. */
    public function assertPeriodsValid(array $periods): void
    {
        $prevEnd = null;
        foreach (array_values($periods) as $i => $p) {
            $start = substr($p['starts_at'], 0, 5);
            $end = substr($p['ends_at'], 0, 5);
            if ($start >= $end) {
                throw ValidationException::withMessages(["periods.$i.ends_at" => ['پایان زنگ باید بعد از شروع آن باشد.']]);
            }
            if ($prevEnd !== null && $start < $prevEnd) {
                throw ValidationException::withMessages(["periods.$i.starts_at" => ['زنگ‌ها نباید هم‌پوشانی داشته باشند.']]);
            }
            $prevEnd = $end;
        }
    }

    /**
     * Dry-run conflict detection. Returns a list of human-readable conflicts (empty = OK).
     * Also used by addEntry() so what is previewed is exactly what is enforced.
     */
    public function conflictsFor(Timetable $tt, array $e, ?int $ignoreEntryId = null): array
    {
        $conflicts = [];
        $period = TimetablePeriod::where('timetable_id', $tt->id)->find($e['period_id']);

        if (! $period || $period->kind !== 'lesson') {
            return [['code' => 'bad_period', 'message' => 'زنگ انتخاب‌شده درسی نیست یا به این برنامه تعلق ندارد.']];
        }
        if (! in_array((int) $e['weekday'], $tt->working_days, true)) {
            $conflicts[] = ['code' => 'non_working_day', 'message' => 'این روز جزو روزهای کاری برنامه نیست.'];
        }
        // The teacher must really be assigned to this subject in this section.
        $assigned = TeacherAssignment::where('section_id', $e['section_id'])->where('subject_id', $e['subject_id'])->first();
        if (! $assigned) {
            $conflicts[] = ['code' => 'not_assigned', 'message' => 'برای این درس در این کلاس معلمی تخصیص داده نشده است.'];
        } elseif ($assigned->teacher_id !== (int) $e['teacher_id']) {
            $conflicts[] = ['code' => 'teacher_mismatch', 'message' => 'معلم انتخابی با معلم تخصیص‌یافته به این درس یکی نیست.'];
        }

        $slot = TimetableEntry::where('timetable_id', $tt->id)->where('weekday', $e['weekday'])->where('period_id', $e['period_id'])
            ->when($ignoreEntryId, fn ($q) => $q->where('id', '!=', $ignoreEntryId));

        if ((clone $slot)->where('section_id', $e['section_id'])->exists()) {
            $conflicts[] = ['code' => 'section_busy', 'message' => 'این کلاس در این زنگ برنامه دیگری دارد.'];
        }
        if ((clone $slot)->where('teacher_id', $e['teacher_id'])->exists()) {
            $conflicts[] = ['code' => 'teacher_busy', 'message' => 'این معلم در این زنگ در کلاس دیگری تدریس می‌کند.'];
        }

        return $conflicts;
    }

    public function addEntry(Timetable $tt, array $e): TimetableEntry
    {
        if ($tt->status === 'archived') {
            throw ValidationException::withMessages(['timetable' => ['برنامه بایگانی‌شده قابل ویرایش نیست.']]);
        }

        return DB::transaction(function () use ($tt, $e) {
            // Serialise writers on this timetable so two requests can't both pass the check.
            Timetable::whereKey($tt->id)->lockForUpdate()->first();

            if ($conflicts = $this->conflictsFor($tt, $e)) {
                throw ValidationException::withMessages(['conflicts' => array_column($conflicts, 'message')])
                    ->status(422);
            }
            try {
                $entry = TimetableEntry::create($e + ['timetable_id' => $tt->id]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['conflicts' => ['تداخل برنامه (محدودیت پایگاه داده).']]);
            }
            Audit::record('timetable.entry_added', $entry, null, $e);
            $this->touchedSchedule($tt, $entry, 'added');

            return $entry;
        });
    }

    public function removeEntry(Timetable $tt, TimetableEntry $entry): void
    {
        abort_if($tt->status === 'archived', 422, 'برنامه بایگانی‌شده قابل ویرایش نیست.');
        $entry->delete();
        Audit::record('timetable.entry_removed', $entry, $entry->toArray());
        $this->touchedSchedule($tt, $entry, 'removed');
    }

    /** Activating archives the previous active version (kept for history) and notifies everyone affected. */
    public function activate(Timetable $tt): Timetable
    {
        return DB::transaction(function () use ($tt) {
            $tt = Timetable::whereKey($tt->id)->lockForUpdate()->firstOrFail();
            abort_if($tt->status === 'archived', 422, 'برنامه بایگانی‌شده را نمی‌توان فعال کرد.');
            abort_unless($tt->periods()->exists() && $tt->entries()->exists(), 422, 'برنامه خالی را نمی‌توان فعال کرد.');

            Timetable::where('status', 'active')->where('id', '!=', $tt->id)->update(['status' => 'archived']);
            $tt->update(['status' => 'active', 'activated_at' => now()]);
            Audit::record('timetable.activated', $tt, null, ['version' => $tt->version]);

            $schoolId = app(CurrentSchool::class)->id();
            $users = collect();
            foreach ($tt->entries()->get(['section_id', 'teacher_id'])->unique(fn ($e) => $e->section_id) as $e) {
                $users = $users->merge(Recipients::forSection($schoolId, $e->section_id));
            }
            $users = $users->merge($tt->entries()->distinct()->pluck('teacher_id')->map(fn ($id) => Recipients::teacherUser($id))->filter());
            $this->notify->send($users, 'schedule.changed', "timetable-activated:{$tt->id}", 'برنامه هفتگی به‌روز شد', "نسخه {$tt->version} برنامه فعال شد.", ['timetable_id' => $tt->id]);

            return $tt;
        });
    }

    /** Substitute teacher or cancel a single occurrence on a date. */
    public function substitute(TimetableEntry $entry, string $date, ?int $substituteTeacherId, string $status, ?string $reason): Substitution
    {
        $dow = (\Carbon\Carbon::parse($date)->dayOfWeek + 1) % 7;
        abort_unless($dow === $entry->weekday, 422, 'تاریخ با روز هفته‌ی این زنگ سازگار نیست.');

        if ($status === 'substitute') {
            abort_unless($substituteTeacherId, 422, 'معلم جایگزین الزامی است.');
            $busyOwn = TimetableEntry::where('timetable_id', $entry->timetable_id)->where('weekday', $entry->weekday)
                ->where('period_id', $entry->period_id)->where('teacher_id', $substituteTeacherId)->exists();
            $busySub = Substitution::where('on_date', $date)->where('status', 'substitute')->where('substitute_teacher_id', $substituteTeacherId)
                ->whereHas('entry', fn ($q) => $q->where('period_id', $entry->period_id))->exists();
            if ($busyOwn || $busySub) {
                throw ValidationException::withMessages(['substitute_teacher_id' => ['معلم جایگزین در این زنگ مشغول است.']]);
            }
        }

        $sub = Substitution::updateOrCreate(
            ['timetable_entry_id' => $entry->id, 'on_date' => $date],
            ['substitute_teacher_id' => $status === 'substitute' ? $substituteTeacherId : null, 'status' => $status, 'reason' => $reason],
        );
        Audit::record('timetable.substitution', $sub, null, $sub->only(['on_date', 'status', 'substitute_teacher_id']));

        $schoolId = app(CurrentSchool::class)->id();
        $users = collect(Recipients::forSection($schoolId, $entry->section_id))->push(Recipients::teacherUser($entry->teacher_id));
        $substituteTeacherId && $users->push(Recipients::teacherUser($substituteTeacherId));
        $this->notify->send($users->filter(), 'schedule.changed', "substitution:{$sub->id}:{$sub->updated_at->timestamp}", $status === 'cancelled' ? 'کلاس لغو شد' : 'تغییر معلم', $reason, ['date' => $date, 'entry_id' => $entry->id]);

        return $sub;
    }

    private function touchedSchedule(Timetable $tt, TimetableEntry $entry, string $what): void
    {
        // Edits to an *active* timetable are announced immediately; drafts are silent.
        if ($tt->status !== 'active') {
            return;
        }
        $schoolId = app(CurrentSchool::class)->id();
        $users = collect(Recipients::forSection($schoolId, $entry->section_id))->push(Recipients::teacherUser($entry->teacher_id))->filter();
        $this->notify->send($users, 'schedule.changed', "entry-$what:{$entry->id}:".now()->timestamp, 'تغییر در برنامه هفتگی', null, ['timetable_id' => $tt->id]);
    }
}

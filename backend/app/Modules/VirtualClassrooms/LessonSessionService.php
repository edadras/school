<?php

namespace App\Modules\VirtualClassrooms;

use App\Models\LessonSession;
use App\Models\SessionHand;
use App\Models\SessionParticipant;
use App\Models\SessionWhiteboardEvent;
use App\Models\Student;
use App\Models\Substitution;
use App\Models\TimetableEntry;
use App\Models\TimetablePeriod;
use App\Models\User;
use App\Modules\Attendance\AttendanceService;
use App\Modules\Audit\Audit;
use App\Modules\Files\SettingsRepository;
use App\Modules\Notifications\NotificationService;
use App\Modules\Realtime\Realtime;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LessonSessionService
{
    public function __construct(
        private MediaProvider $media, private Access $access, private AttendanceService $attendance,
        private NotificationService $notify, private CurrentSchool $current, private SettingsRepository $settings,
        private Realtime $realtime,
    ) {}

    /** Idempotent: one session per (timetable entry, date). Called by the bell engine at lesson start and by the teacher. */
    public function ensureForEntry(TimetableEntry $entry, string $date, ?TimetablePeriod $period = null): ?LessonSession
    {
        $period ??= TimetablePeriod::find($entry->period_id);
        $sub = Substitution::where('timetable_entry_id', $entry->id)->whereDate('on_date', $date)->first();
        if ($sub?->status === 'cancelled') {
            return null;
        }
        $school = \App\Models\School::find($this->current->id());
        $teacherId = $sub?->substitute_teacher_id ?? $entry->teacher_id;
        $subject = \App\Models\Subject::find($entry->subject_id);

        return LessonSession::firstOrCreate(['timetable_entry_id' => $entry->id, 'on_date' => $date], [
            'section_id' => $entry->section_id, 'subject_id' => $entry->subject_id, 'teacher_id' => $teacherId,
            'kind' => $sub ? 'substitute' : 'regular', 'title' => $subject?->name ?? 'کلاس',
            'scheduled_start' => Carbon::parse("$date {$period->starts_at}", $school->timezone)->utc(),
            'scheduled_end' => Carbon::parse("$date {$period->ends_at}", $school->timezone)->utc(),
            'room_name' => 's'.$this->current->id().'-'.Str::lower(Str::random(16)), 'provider' => $this->media->name(),
            'recording_enabled' => false,
        ]);
    }

    /** Make-up / replacement class outside the weekly grid. */
    public function createExtra(User $by, array $d): LessonSession
    {
        abort_unless($this->access->canTeach($by, $d['section_id'], $d['subject_id']), 403, 'شما معلم این درس در این کلاس نیستید.');
        $teacherId = $d['teacher_id'] ?? $this->access->teacherId($by)
            ?? \App\Models\TeacherAssignment::where('section_id', $d['section_id'])->where('subject_id', $d['subject_id'])->value('teacher_id');
        abort_unless($teacherId, 422, 'معلمی برای این کلاس مشخص نیست.');
        $start = Carbon::parse($d['scheduled_start'])->utc();
        $s = LessonSession::create([
            'section_id' => $d['section_id'], 'subject_id' => $d['subject_id'], 'teacher_id' => $teacherId, 'kind' => $d['kind'],
            'title' => $d['title'], 'on_date' => $start->copy()->setTimezone(\App\Models\School::find($this->current->id())->timezone)->toDateString(),
            'scheduled_start' => $start, 'scheduled_end' => Carbon::parse($d['scheduled_end'])->utc(),
            'room_name' => 's'.$this->current->id().'-'.Str::lower(Str::random(16)), 'provider' => $this->media->name(),
        ]);
        $this->notify->send(Recipients::forSection($this->current->id(), $s->section_id), 'session.scheduled', "session-sched:{$s->id}",
            ($d['kind'] === 'makeup' ? 'کلاس جبرانی: ' : 'کلاس جایگزین: ').$s->title, null, ['session_id' => $s->id]);
        Audit::record('session.created_extra', $s, null, $s->only(['kind', 'title']));

        return $s;
    }

    public function isHost(User $u, LessonSession $s): bool
    {
        $tid = $this->access->teacherId($u);

        // Only the class's own teacher (or the assigned substitute) hosts; managers observe/monitor, they don't take over.
        return $tid !== null && $tid === $s->teacher_id;
    }

    /** Host starts the class: creates the SFU room, flips status, tells the class. */
    public function start(LessonSession $s, User $host): LessonSession
    {
        abort_unless($this->isHost($host, $s), 403, 'فقط معلم کلاس می‌تواند آن را شروع کند.');
        if ($s->status === 'live') {
            return $s;
        }
        abort_if(in_array($s->status, ['ended'], true), 422, 'این کلاس پایان یافته است.');
        $this->assertConcurrencyLimit($s);

        $this->media->createRoom($s->room_name, (int) config('media.max_participants'));
        $s->update(['status' => 'live', 'started_at' => $s->started_at ?? now()]);
        $this->notify->send(Recipients::forSection($this->current->id(), $s->section_id), 'session.live', "session-live:{$s->id}",
            'کلاس آنلاین شروع شد: '.$s->title, null, ['session_id' => $s->id]);
        $this->realtime->session($s, 'session.live', ['status' => 'live']);

        return $s;
    }

    public function join(LessonSession $s, User $u, ?string $client = null): array
    {
        $isHost = $this->isHost($u, $s);
        $studentId = $this->access->ownStudentId($u);
        $observer = ! $isHost && ! $studentId && $this->access->can($u, 'sessions.monitor');

        abort_unless($isHost || $observer || ($studentId && $this->access->canViewSection($u, $s->section_id)), 403, 'اجازهٔ ورود به این کلاس را ندارید.');
        if ($s->status !== 'live') {
            throw ValidationException::withMessages(['session' => [$s->status === 'ended' ? 'کلاس پایان یافته است.' : 'کلاس هنوز شروع نشده است.']]);
        }
        $active = SessionParticipant::where('lesson_session_id', $s->id)->whereNull('left_at')->distinct('user_id')->count('user_id');
        if (! $isHost && $active >= (int) config('media.max_participants')) {
            throw ValidationException::withMessages(['session' => ['ظرفیت کلاس تکمیل است.']]);
        }

        $role = $isHost ? 'host' : ($observer ? 'observer' : 'participant');
        $conn = $this->media->joinToken($s->room_name, $this->identity($u), $u->name, $role);

        // A reconnect closes the dangling row first so durations stay truthful.
        SessionParticipant::where('lesson_session_id', $s->id)->where('user_id', $u->id)->whereNull('left_at')->update(['left_at' => now()]);
        SessionParticipant::create(['lesson_session_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'joined_at' => now(), 'client' => $client ? Str::limit($client, 60, '') : null]);
        if ($studentId) {
            $this->attendance->recordJoin($s, Student::find($studentId));
        }
        $this->realtime->session($s, 'participant.joined', ['user_id' => $u->id, 'name' => $u->name, 'role' => $role]);

        return $conn + ['role' => $role, 'room' => $s->room_name, 'recording' => (bool) $s->recording_enabled, 'session' => $s->only(['id', 'title', 'status'])];
    }

    public function leave(LessonSession $s, User $u): void
    {
        SessionParticipant::where('lesson_session_id', $s->id)->where('user_id', $u->id)->whereNull('left_at')->update(['left_at' => now()]);
        SessionHand::where('lesson_session_id', $s->id)->where('user_id', $u->id)->whereNull('lowered_at')->update(['lowered_at' => now()]);
        $this->realtime->session($s, 'participant.left', ['user_id' => $u->id]);
    }

    public function end(LessonSession $s, User $by, bool $notHeld = false): LessonSession
    {
        abort_unless($this->isHost($by, $s) || $this->access->can($by, 'sessions.monitor'), 403);

        return $this->finish($s, $notHeld ? 'not_held' : 'ended');
    }

    /** Shared by manual end, bell engine (lesson ended) and webhook (room finished). Idempotent. */
    public function finish(LessonSession $s, string $status): LessonSession
    {
        if (in_array($s->status, ['ended', 'not_held'], true)) {
            return $s;
        }
        $wasLive = $s->status === 'live';
        $this->media->endRoom($s->room_name);
        SessionParticipant::where('lesson_session_id', $s->id)->whereNull('left_at')->update(['left_at' => now()]);
        $s->update(['status' => $status, 'ended_at' => now()]);
        if ($wasLive || $status === 'ended') {
            $this->attendance->closeSession($s);
        }
        $this->realtime->session($s, 'session.ended', ['status' => $status]);

        return $s;
    }

    /** Bell engine hook at the end of the period: unstarted classes are recorded as "not held" and reported. */
    public function onPeriodEnd(LessonSession $s): void
    {
        if ($s->status === 'scheduled') {
            $this->finish($s, 'not_held');
            $admins = \App\Models\SchoolUserMembership::where('school_id', $this->current->id())
                ->whereIn('role_id', \App\Models\Role::whereIn('key', ['school_admin', 'deputy'])->select('id'))->pluck('user_id');
            $this->notify->send($admins, 'session.not_held', "not-held:{$s->id}", 'کلاس برگزار نشد: '.$s->title, null, ['session_id' => $s->id]);
        }
    }

    public function muteParticipant(LessonSession $s, User $host, int $userId, bool $muted): void
    {
        abort_unless($this->isHost($host, $s), 403);
        abort_unless(SessionParticipant::where('lesson_session_id', $s->id)->where('user_id', $userId)->whereNull('left_at')->exists(), 404);
        $this->media->setMicrophoneMuted($s->room_name, $this->identity(User::findOrFail($userId)), $muted);
        Audit::record($muted ? 'session.mute' : 'session.unmute', $s, null, ['user_id' => $userId]);
        $this->realtime->session($s, $muted ? 'participant.muted' : 'participant.unmuted', ['user_id' => $userId]);
    }

    public function removeParticipant(LessonSession $s, User $host, int $userId): void
    {
        abort_unless($this->isHost($host, $s), 403);
        $this->media->removeParticipant($s->room_name, $this->identity(User::findOrFail($userId)));
        SessionParticipant::where('lesson_session_id', $s->id)->where('user_id', $userId)->whereNull('left_at')->update(['left_at' => now()]);
        Audit::record('session.remove_participant', $s, null, ['user_id' => $userId]);
        $this->realtime->session($s, 'participant.removed', ['user_id' => $userId]);
    }

    public function raiseHand(LessonSession $s, User $u, bool $raise): void
    {
        abort_unless(SessionParticipant::where('lesson_session_id', $s->id)->where('user_id', $u->id)->whereNull('left_at')->exists(), 403, 'ابتدا وارد کلاس شوید.');
        $open = SessionHand::where('lesson_session_id', $s->id)->where('user_id', $u->id)->whereNull('lowered_at')->first();
        if ($raise && ! $open) {
            SessionHand::create(['lesson_session_id' => $s->id, 'user_id' => $u->id, 'raised_at' => now()]);
        } elseif (! $raise && $open) {
            $open->update(['lowered_at' => now()]);
        }
        $this->realtime->session($s, $raise ? 'hand.raised' : 'hand.lowered', ['user_id' => $u->id, 'name' => $u->name]);
    }

    /** Ordered queue of raised hands = speaking turns. */
    public function hands(LessonSession $s): array
    {
        return SessionHand::where('lesson_session_id', $s->id)->whereNull('lowered_at')->orderBy('raised_at')->get(['user_id', 'raised_at'])->all();
    }

    /** Whiteboard strokes are persisted so late joiners / reconnects can replay them. */
    public function whiteboard(LessonSession $s, User $u, array $payload): SessionWhiteboardEvent
    {
        abort_unless($this->isHost($u, $s), 403, 'فقط معلم می‌تواند روی تخته بنویسد.');
        $e = SessionWhiteboardEvent::create(['lesson_session_id' => $s->id, 'user_id' => $u->id, 'payload' => $payload]);
        if (($payload['type'] ?? '') === 'clear') {
            // Older events are superseded by a clear; keep only the clear marker.
            SessionWhiteboardEvent::where('lesson_session_id', $s->id)->where('id', '<', $e->id)->delete();
        }
        $this->realtime->session($s, 'whiteboard', ['id' => $e->id, 'payload' => $payload]);

        return $e;
    }

    private function assertConcurrencyLimit(LessonSession $s): void
    {
        $max = \App\Models\SchoolSubscription::where('school_id', $this->current->id())->where('status', 'active')->value('max_live_sessions');
        if ($max !== null && LessonSession::where('status', 'live')->where('id', '!=', $s->id)->count() >= $max) {
            throw ValidationException::withMessages(['limit' => ['سقف کلاس‌های هم‌زمان طرح اشتراک پر شده است.']]);
        }
    }

    public function identity(User $u): string
    {
        return 'u'.$u->id;
    }
}

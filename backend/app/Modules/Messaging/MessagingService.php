<?php

namespace App\Modules\Messaging;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReport;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolUserMembership;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Files\FileService;
use App\Modules\Files\SettingsRepository;
use App\Modules\Notifications\NotificationService;
use App\Modules\Realtime\Realtime;
use App\Modules\Scheduling\BellEngine;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * School-controlled messenger. Policy (per school, stored in school_settings):
 *   messaging.enabled (true) · messaging.quiet_hours [{start,end}] · messaging.block_during_lessons (false)
 *   messaging.student_to_student (false) · messaging.student_to_teacher (false) · messaging.guardian_to_teacher (true)
 * Staff (messaging.moderate) are exempt from quiet hours and direct-message limits.
 */
class MessagingService
{
    public function __construct(
        private Access $access, private SettingsRepository $settings, private CurrentSchool $current,
        private FileService $files, private Realtime $realtime, private NotificationService $notify,
    ) {}

    // -------------------------------------------------------------------- policy
    public function assertMessagingAllowed(User $u): void
    {
        if ($this->access->can($u, 'messaging.moderate')) {
            return;
        }
        if (! $this->settings->get('messaging.enabled', true)) {
            throw ValidationException::withMessages(['messaging' => ['پیام‌رسان توسط مدرسه غیرفعال شده است.']]);
        }
        $tz = School::find($this->current->id())->timezone;
        $local = now($tz);
        foreach ((array) $this->settings->get('messaging.quiet_hours', []) as $w) {
            if ($this->inWindow($local->format('H:i'), $w['start'] ?? '00:00', $w['end'] ?? '00:00')) {
                throw ValidationException::withMessages(['messaging' => ['در این ساعات پیام‌رسان غیرفعال است.']]);
            }
        }
        if ($this->settings->get('messaging.block_during_lessons', false) && $this->lessonInProgress($local)) {
            throw ValidationException::withMessages(['messaging' => ['در زمان کلاس ارسال پیام غیرفعال است.']]);
        }
    }

    private function inWindow(string $now, string $start, string $end): bool
    {
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end); // wraps midnight
    }

    private function lessonInProgress(Carbon $local): bool
    {
        $tt = \App\Models\Timetable::where('status', 'active')->first();
        if (! $tt || ! in_array(BellEngine::weekdayOf($local), $tt->working_days, true)) {
            return false;
        }
        $t = $local->format('H:i:s');

        return \App\Models\TimetablePeriod::where('timetable_id', $tt->id)->where('kind', 'lesson')->where('starts_at', '<=', $t)->where('ends_at', '>', $t)->exists();
    }

    // -------------------------------------------------------------------- conversations
    /** The class chat for a section. Participants are synced on every access (new students/teachers join automatically). */
    public function classConversation(Section $section): Conversation
    {
        $c = Conversation::firstOrCreate(['type' => 'class', 'section_id' => $section->id], ['title' => 'گفت‌وگوی کلاس '.$section->name]);
        $userIds = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.section_id', $section->id)->where('e.status', 'active')->whereNotNull('s.user_id')->pluck('s.user_id')
            ->merge(DB::table('teacher_assignments as ta')->join('teachers as t', 't.id', '=', 'ta.teacher_id')->where('ta.section_id', $section->id)->pluck('t.user_id'));
        $have = ConversationParticipant::where('conversation_id', $c->id)->pluck('user_id');
        foreach ($userIds->unique()->diff($have) as $uid) {
            ConversationParticipant::create(['conversation_id' => $c->id, 'user_id' => $uid, 'role' => 'member']);
        }

        return $c;
    }

    /** Direct chat. Allowed pairs are governed by school policy. */
    public function direct(User $from, int $toUserId, ?int $assignmentId = null): Conversation
    {
        $this->assertMessagingAllowed($from);
        abort_if($toUserId === $from->id, 422, 'گفت‌وگو با خود ممکن نیست.');
        $to = User::findOrFail($toUserId);
        abort_unless(SchoolUserMembership::where('school_id', $this->current->id())->where('user_id', $to->id)->where('status', 'active')->exists(), 404);

        if (! $assignmentId) {
            $this->assertPairAllowed($from, $to);
        }
        $key = $assignmentId ? "a$assignmentId:".min($from->id, $to->id).':'.max($from->id, $to->id) : min($from->id, $to->id).':'.max($from->id, $to->id);
        $c = Conversation::firstOrCreate(['direct_key' => $key], ['type' => 'direct', 'assignment_id' => $assignmentId, 'created_by' => $from->id]);
        foreach ([$from->id, $to->id] as $uid) {
            ConversationParticipant::firstOrCreate(['conversation_id' => $c->id, 'user_id' => $uid], ['role' => 'member']);
        }

        return $c;
    }

    private function assertPairAllowed(User $a, User $b): void
    {
        $roles = fn (User $u) => Role::whereIn('id', SchoolUserMembership::where('school_id', $this->current->id())->where('user_id', $u->id)->select('role_id'))->pluck('key')->all();
        [$ra, $rb] = [$roles($a), $roles($b)];
        $has = fn (array $r, string $k) => in_array($k, $r, true);
        $staff = fn (array $r) => $has($r, 'school_admin') || $has($r, 'deputy');

        if ($staff($ra) || $staff($rb)) {
            return;
        }
        $pair = fn ($x, $y) => ($has($ra, $x) && $has($rb, $y)) || ($has($ra, $y) && $has($rb, $x));
        $allowed = match (true) {
            $pair('student', 'student') => (bool) $this->settings->get('messaging.student_to_student', false),
            $pair('student', 'teacher') => (bool) $this->settings->get('messaging.student_to_teacher', false),
            $pair('guardian', 'teacher') => (bool) $this->settings->get('messaging.guardian_to_teacher', true),
            $pair('teacher', 'teacher') => true,
            default => false,
        };
        if (! $allowed) {
            throw ValidationException::withMessages(['messaging' => ['پیام خصوصی بین این دو نقش طبق سیاست مدرسه مجاز نیست.']]);
        }
    }

    public function assertParticipant(User $u, Conversation $c): ConversationParticipant
    {
        return ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $u->id)->first()
            ?? abort(404);
    }

    // -------------------------------------------------------------------- messages
    public function send(User $u, Conversation $c, array $d, array $fileIds = []): Message
    {
        $this->assertParticipant($u, $c);
        $this->assertMessagingAllowed($u);
        if ($c->is_locked && ! $this->access->can($u, 'messaging.moderate') && ! $this->isOwnerTeacher($u, $c)) {
            throw ValidationException::withMessages(['messaging' => ['این گفت‌وگو قفل شده است.']]);
        }
        if (! empty($d['client_id'])) {
            if ($dup = Message::withTrashed()->where('conversation_id', $c->id)->where('user_id', $u->id)->where('client_id', $d['client_id'])->first()) {
                return $dup; // retried send after a network drop: idempotent
            }
        }
        if (blank($d['body'] ?? null) && ! $fileIds) {
            throw ValidationException::withMessages(['body' => ['پیام خالی است.']]);
        }
        if (! empty($d['reply_to_id'])) {
            abort_unless(Message::where('conversation_id', $c->id)->whereKey($d['reply_to_id'])->exists(), 422, 'پیام مرجع یافت نشد.');
        }

        $kind = $d['kind'] ?? 'text';
        $m = DB::transaction(function () use ($u, $c, $d, $fileIds, &$kind) {
            $m = Message::create([
                'conversation_id' => $c->id, 'user_id' => $u->id, 'reply_to_id' => $d['reply_to_id'] ?? null, 'body' => $d['body'] ?? null,
                'client_id' => $d['client_id'] ?? null, 'kind' => 'text',
            ]);
            foreach ($fileIds as $fid) {
                $f = $this->files->claim($fid, $u, 'message', $m->id);
                MessageAttachment::create(['message_id' => $m->id, 'file_id' => $fid]);
                $kind = str_starts_with($f->mime, 'audio/') ? 'audio' : 'file';
            }
            $m->update(['kind' => $fileIds ? $kind : 'text']);

            return $m;
        });
        $c->touch();
        $payload = $this->present($m->load('attachments'));
        $this->realtime->conversation($c->id, 'message.sent', $payload);
        if ($c->type === 'direct') {
            $others = ConversationParticipant::where('conversation_id', $c->id)->where('user_id', '!=', $u->id)->pluck('user_id');
            foreach ($others as $uid) {
                $this->notify->send([$uid], 'message.new', "msg:{$m->id}:$uid", 'پیام جدید از '.$u->name, str($m->body)->limit(80)->toString(), ['conversation_id' => $c->id]);
            }
        }

        return $m;
    }

    private function isOwnerTeacher(User $u, Conversation $c): bool
    {
        return $c->section_id && $this->access->canTeach($u, $c->section_id);
    }

    public function present(Message $m): array
    {
        return [
            'id' => $m->id, 'conversation_id' => $m->conversation_id, 'user_id' => $m->user_id, 'kind' => $m->kind,
            'body' => $m->trashed() ? null : $m->body, 'deleted' => $m->trashed(), 'reply_to_id' => $m->reply_to_id,
            'attachments' => $m->relationLoaded('attachments') ? $m->attachments->pluck('file_id')->all() : [],
            'created_at' => $m->created_at?->toIso8601String(), 'client_id' => $m->client_id,
        ];
    }

    public function markRead(User $u, Conversation $c): void
    {
        $last = Message::where('conversation_id', $c->id)->max('id') ?? 0;
        ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $u->id)->update(['last_read_message_id' => $last]);
    }

    // -------------------------------------------------------------------- moderation
    public function report(User $u, Message $m, string $reason): MessageReport
    {
        $this->assertParticipant($u, Conversation::findOrFail($m->conversation_id));
        $r = MessageReport::firstOrCreate(['message_id' => $m->id, 'reporter_id' => $u->id], ['reason' => $reason]);
        $mods = SchoolUserMembership::where('school_id', $this->current->id())
            ->whereIn('role_id', Role::whereIn('key', ['school_admin', 'deputy'])->select('id'))->pluck('user_id');
        $this->notify->send($mods, 'message.reported', "report:{$r->id}", 'گزارش محتوای نامناسب', $reason, ['report_id' => $r->id]);

        return $r;
    }

    /** Moderator view of a reported message with limited context; every access is audited. */
    public function reportContext(User $mod, MessageReport $r): array
    {
        $m = Message::withTrashed()->findOrFail($r->message_id);
        $ctx = Message::withTrashed()->where('conversation_id', $m->conversation_id)->where('id', '>=', $m->id - 10)->where('id', '<=', $m->id + 10)->orderBy('id')->get();
        Audit::record('message.report_viewed', $r, null, ['message_id' => $m->id]);

        return ['report' => $r, 'message' => $this->present($m), 'context' => $ctx->map(fn ($x) => $this->present($x))->values()];
    }

    public function resolveReport(User $mod, MessageReport $r, string $action, ?string $note): MessageReport
    {
        if ($r->status !== 'open') {
            throw ValidationException::withMessages(['report' => ['این گزارش قبلاً رسیدگی شده است.']]);
        }
        if ($action === 'delete_message') {
            $m = Message::find($r->message_id);
            $m?->delete();
            $m && $this->realtime->conversation($m->conversation_id, 'message.deleted', ['id' => $m->id]);
        }
        $r->update(['status' => $action === 'dismiss' ? 'dismissed' : 'actioned', 'resolved_by' => $mod->id, 'resolution_note' => $note]);
        Audit::record('message.report_resolved', $r, null, ['action' => $action]);

        return $r;
    }
}

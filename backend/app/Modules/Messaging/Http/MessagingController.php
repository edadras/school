<?php

namespace App\Modules\Messaging\Http;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\Section;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Messaging\MessagingService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingController extends Controller
{
    public function __construct(private MessagingService $svc, private Access $access) {}

    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        // Make sure the class chats of my sections exist before listing.
        foreach ($this->access->sectionIds($request->user()) ?? [] as $sid) {
            $this->svc->classConversation(Section::findOrFail($sid));
        }
        $rows = Conversation::whereIn('id', ConversationParticipant::where('user_id', $uid)->select('conversation_id'))->orderByDesc('updated_at')->get();
        $last = ConversationParticipant::where('user_id', $uid)->pluck('last_read_message_id', 'conversation_id');

        return response()->json(['data' => $rows->map(fn ($c) => $c->only(['id', 'type', 'title', 'section_id', 'assignment_id', 'is_locked']) + [
            'unread' => Message::where('conversation_id', $c->id)->where('id', '>', $last[$c->id] ?? 0)->where('user_id', '!=', $uid)->count(),
            'last' => ($m = Message::where('conversation_id', $c->id)->latest('id')->first()) ? $this->svc->present($m) : null,
        ])]);
    }

    /** Display names of a conversation's members (only for participants). */
    public function participants(Request $request, int $id): JsonResponse
    {
        $c = Conversation::findOrFail($id);
        $this->svc->assertParticipant($request->user(), $c);
        $rows = ConversationParticipant::where('conversation_id', $c->id)->get();
        $users = \App\Models\User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');
        $roles = \App\Models\SchoolUserMembership::where('school_id', app(\App\Modules\Tenancy\CurrentSchool::class)->id())->whereIn('user_id', $rows->pluck('user_id'))
            ->with('role:id,key')->get()->groupBy('user_id')->map(fn ($g) => $g->first()->role->key);

        return response()->json(['data' => $rows->map(fn ($p) => ['user_id' => $p->user_id, 'name' => $users[$p->user_id] ?? '—', 'role' => $roles[$p->user_id] ?? null])->values(), 'conversation' => $c->only(['id', 'type', 'title', 'is_locked', 'section_id'])]);
    }

    /** People the caller may try to message (final permission is still enforced by school policy on creation). */
    public function contacts(Request $request): JsonResponse
    {
        $u = $request->user();
        $schoolId = app(\App\Modules\Tenancy\CurrentSchool::class)->id();
        $secs = $this->access->sectionIds($u);
        $base = \App\Models\SchoolUserMembership::where('school_id', $schoolId)->where('status', 'active')->where('user_id', '!=', $u->id);
        if ($secs === null) {                                      // staff: everyone in the school
            $ids = $base->pluck('user_id');
        } else {
            $teachers = \Illuminate\Support\Facades\DB::table('teacher_assignments as ta')->join('teachers as t', 't.id', '=', 'ta.teacher_id')->whereIn('ta.section_id', $secs)->pluck('t.user_id');
            $guardians = \Illuminate\Support\Facades\DB::table('enrollments as e')->join('student_guardians as sg', 'sg.student_id', '=', 'e.student_id')->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
                ->whereIn('e.section_id', $secs)->where('e.status', 'active')->where('sg.status', 'approved')->pluck('g.user_id');
            $staff = \Illuminate\Support\Facades\DB::table('school_user_memberships as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.school_id', $schoolId)->whereIn('r.key', ['school_admin', 'deputy'])->pluck('m.user_id');
            $ids = $this->access->teacherId($u) ? $teachers->merge($guardians)->merge($staff) : $teachers->merge($staff);   // guardians/students: teachers + managers
        }
        $ids = $ids->unique()->reject(fn ($x) => $x === $u->id)->values();
        $roles = \App\Models\SchoolUserMembership::where('school_id', $schoolId)->whereIn('user_id', $ids)->with('role:id,key')->get()->groupBy('user_id')->map(fn ($g) => $g->first()->role->key);

        return response()->json(['data' => \App\Models\User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['user_id' => $x->id, 'name' => $x->name, 'role' => $roles[$x->id] ?? null])->values()]);
    }

    public function direct(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'integer'], 'assignment_id' => ['nullable', ResourceRegistry::existsInSchool('assignments')]]);

        return response()->json(['data' => $this->svc->direct($request->user(), $d['user_id'], $d['assignment_id'] ?? null)->only(['id', 'type'])], 201);
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $c = Conversation::findOrFail($id);
        $this->svc->assertParticipant($request->user(), $c);
        $q = Message::withTrashed()->where('conversation_id', $c->id)->with('attachments');
        if ($request->filled('before_id')) {
            $q->where('id', '<', $request->integer('before_id'));
        }
        if ($request->filled('after_id')) {                        // reconnect sync
            $q->where('id', '>', $request->integer('after_id'));
        }
        $rows = $q->orderByDesc('id')->limit(min((int) $request->integer('limit', 50), 100))->get()->reverse()->values();
        $request->boolean('mark_read', true) && $this->svc->markRead($request->user(), $c);

        return response()->json(['data' => $rows->map(fn ($m) => $this->svc->present($m))]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $c = Conversation::findOrFail($id);
        $d = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'], 'reply_to_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'string', 'max:64'],
            'file_ids' => ['sometimes', 'array', 'max:5'], 'file_ids.*' => ['integer'],
        ]);
        $files = $d['file_ids'] ?? [];
        unset($d['file_ids']);
        $m = $this->svc->send($request->user(), $c, $d, $files);

        return response()->json(['data' => $this->svc->present($m->load('attachments'))], $m->wasRecentlyCreated ? 201 : 200);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $c = Conversation::findOrFail($id);
        abort_unless($this->access->can($request->user(), 'messaging.moderate') || ($c->section_id && $this->access->canTeach($request->user(), $c->section_id)), 403);
        $c->update(['is_locked' => $request->boolean('locked', true)]);

        return response()->json(['data' => $c->only(['id', 'is_locked'])]);
    }

    public function report(Request $request, int $messageId): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->svc->report($request->user(), Message::findOrFail($messageId), $d['reason'])->only(['id', 'status'])], 201);
    }

    public function reports(Request $request): JsonResponse
    {
        $q = MessageReport::query()->orderByDesc('id');
        $request->filled('status') && $q->where('status', $request->input('status'));

        return response()->json($q->paginate(25));
    }

    public function reportShow(Request $request, int $id): JsonResponse
    {
        return response()->json($this->svc->reportContext($request->user(), MessageReport::findOrFail($id)));
    }

    public function reportResolve(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['action' => ['required', 'in:delete_message,dismiss,warn'], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->svc->resolveReport($request->user(), MessageReport::findOrFail($id), $d['action'], $d['note'] ?? null)]);
    }
}

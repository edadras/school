<?php

namespace App\Modules\VirtualClassrooms\Http;

use App\Http\Controllers\Controller;
use App\Models\LessonSession;
use App\Models\SessionIssue;
use App\Models\SessionParticipant;
use App\Models\SessionWhiteboardEvent;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use App\Modules\Files\SettingsRepository;
use App\Modules\Tenancy\Access;
use App\Modules\VirtualClassrooms\LessonSessionService;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    public function __construct(private LessonSessionService $svc, private Access $access) {}

    /** Own classes: students/guardians see their sections', teachers theirs, monitors all. Filter: from/to date, status. */
    public function index(Request $request): JsonResponse
    {
        $q = LessonSession::query()->orderBy('scheduled_start');
        $secs = $this->access->sectionIds($request->user());
        $secs !== null && $q->whereIn('section_id', $secs);
        $request->filled('status') && $q->where('status', $request->input('status'));
        $request->filled('from') && $q->whereDate('on_date', '>=', $request->input('from'));
        $request->filled('to') && $q->whereDate('on_date', '<=', $request->input('to'));
        $request->boolean('today') && $q->whereDate('on_date', now()->setTimezone(\App\Models\School::find(app(\App\Modules\Tenancy\CurrentSchool::class)->id())->timezone)->toDateString());

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200))->through(fn ($s) => $this->present($s)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $s = $this->find($request, $id);

        return response()->json(['data' => $this->present($s)] + [
            'hands' => $this->svc->isHost($request->user(), $s) ? $this->svc->hands($s) : [],
            'participants' => $this->svc->isHost($request->user(), $s) || $this->access->can($request->user(), 'sessions.monitor')
                ? SessionParticipant::where('lesson_session_id', $s->id)->whereNull('left_at')->get(['user_id', 'role', 'joined_at']) : [],
        ]);
    }

    public function storeExtra(Request $request): JsonResponse
    {
        $d = $request->validate([
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'kind' => ['required', Rule::in(['makeup', 'substitute'])], 'title' => ['required', 'string', 'max:150'],
            'scheduled_start' => ['required', 'date', 'after:now'], 'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ]);

        return response()->json(['data' => $this->present($this->svc->createExtra($request->user(), $d))], 201);
    }

    /** Teacher opens "today's lesson" from the timetable even if the bell has not created it yet. */
    public function startForEntry(Request $request): JsonResponse
    {
        $d = $request->validate(['timetable_entry_id' => ['required', ResourceRegistry::existsInSchool('timetable_entries')]]);
        $entry = \App\Models\TimetableEntry::findOrFail($d['timetable_entry_id']);
        $school = \App\Models\School::find(app(\App\Modules\Tenancy\CurrentSchool::class)->id());
        $date = now($school->timezone)->toDateString();
        abort_unless($this->access->canTeach($request->user(), $entry->section_id, $entry->subject_id), 403);
        $s = $this->svc->ensureForEntry($entry, $date);
        abort_unless($s, 422, 'این کلاس امروز لغو شده است.');

        return response()->json(['data' => $this->present($this->svc->start($s, $request->user()))]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->svc->start($this->find($request, $id), $request->user()))]);
    }

    public function join(Request $request, int $id): JsonResponse
    {
        return response()->json($this->svc->join($this->find($request, $id), $request->user(), $request->userAgent()));
    }

    public function leave(Request $request, int $id): JsonResponse
    {
        $this->svc->leave($this->find($request, $id), $request->user());

        return response()->json(null, 204);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->svc->end($this->find($request, $id), $request->user(), $request->boolean('not_held')))]);
    }

    public function mute(Request $request, int $id, int $userId): JsonResponse
    {
        $this->svc->muteParticipant($this->find($request, $id), $request->user(), $userId, $request->boolean('muted', true));

        return response()->json(null, 204);
    }

    public function kick(Request $request, int $id, int $userId): JsonResponse
    {
        $this->svc->removeParticipant($this->find($request, $id), $request->user(), $userId);

        return response()->json(null, 204);
    }

    public function hand(Request $request, int $id): JsonResponse
    {
        $this->svc->raiseHand($this->find($request, $id), $request->user(), $request->boolean('raise', true));

        return response()->json(null, 204);
    }

    public function whiteboardStore(Request $request, int $id): JsonResponse
    {
        $p = $request->validate(['type' => ['required', Rule::in(['stroke', 'shape', 'text', 'clear', 'page', 'image'])], 'data' => ['nullable', 'array'], 'page' => ['nullable', 'integer']]);
        $e = $this->svc->whiteboard($this->find($request, $id), $request->user(), $p);

        return response()->json(['data' => ['id' => $e->id]], 201);
    }

    /** Late joiners / reconnects replay from since_id. */
    public function whiteboardIndex(Request $request, int $id): JsonResponse
    {
        $s = $this->find($request, $id);
        $rows = SessionWhiteboardEvent::where('lesson_session_id', $s->id)->where('id', '>', $request->integer('since_id'))->orderBy('id')->limit(2000)->get(['id', 'payload']);

        return response()->json(['data' => $rows]);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $s = $this->find($request, $id);
        $d = $request->validate(['type' => ['required', Rule::in(['audio', 'video', 'connection', 'teacher_absent', 'other'])], 'note' => ['nullable', 'string', 'max:500']]);
        $i = SessionIssue::create($d + ['lesson_session_id' => $s->id, 'user_id' => $request->user()->id]);
        if ($d['type'] === 'teacher_absent' && $s->status === 'scheduled') {
            $s->update(['notes' => 'گزارش غیبت معلم توسط دانش‌آموز']);
        }

        return response()->json(['data' => $i], 201);
    }

    /** Monitoring: sessions + technical problems for school managers. */
    public function report(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(7)->toDateString());
        $to = $request->input('to', now()->toDateString());
        $rows = LessonSession::whereDate('on_date', '>=', $from)->whereDate('on_date', '<=', $to)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $issues = SessionIssue::whereDate('created_at', '>=', $from)->selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type');

        return response()->json(['by_status' => (object) collect($rows)->all(), 'issues' => (object) collect($issues)->all(), 'live_now' => LessonSession::where('status', 'live')->count()]);
    }

    /** Recording is opt-in per school policy AND per session; participants are told in real time and in the join payload. */
    public function recording(Request $request, int $id, \App\Modules\VirtualClassrooms\RecordingService $rec): JsonResponse
    {
        $s = $this->find($request, $id);
        abort_unless($this->svc->isHost($request->user(), $s), 403);
        $request->boolean('enabled') ? $rec->start($s, $request->user()) : $rec->stop($s);

        return response()->json(['data' => $this->present($s->refresh())]);
    }

    /** Recordings of one class: host and school managers only (never students or guardians). */
    public function recordings(Request $request, int $id): JsonResponse
    {
        $s = $this->find($request, $id);
        abort_unless($this->svc->isHost($request->user(), $s) || $this->access->can($request->user(), 'sessions.monitor'), 403);

        return response()->json(['data' => \App\Models\SessionRecording::where('lesson_session_id', $s->id)->orderByDesc('id')
            ->get(['id', 'status', 'file_id', 'size', 'duration_seconds', 'error', 'started_at', 'ended_at'])]);
    }

    public function media(MediaProvider $media): JsonResponse
    {
        return response()->json(['provider' => $media->name(), 'configured' => $media->isConfigured()]);
    }

    private function find(Request $request, int $id): LessonSession
    {
        $s = LessonSession::findOrFail($id);
        abort_unless($this->access->canViewSection($request->user(), $s->section_id) || $this->access->can($request->user(), 'sessions.monitor'), 404);

        return $s;
    }

    private function present(LessonSession $s): array
    {
        return $s->only(['id', 'section_id', 'subject_id', 'teacher_id', 'timetable_entry_id', 'kind', 'title', 'status', 'on_date', 'scheduled_start', 'scheduled_end', 'started_at', 'ended_at', 'recording_enabled']);
    }
}

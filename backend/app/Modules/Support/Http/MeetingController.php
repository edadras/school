<?php

namespace App\Modules\Support\Http;

use App\Http\Controllers\Controller;
use App\Models\MeetingRequest;
use App\Models\Teacher;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use App\Modules\Notifications\NotificationService;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeetingController extends Controller
{
    public function __construct(private Access $access, private NotificationService $notify) {}

    /** Guardians see their own requests; teachers those addressed to them; managers all. */
    public function index(Request $request): JsonResponse
    {
        $q = MeetingRequest::query()->orderByDesc('id');
        $u = $request->user();
        if (! $this->access->isStaff($u)) {
            $tid = $this->access->teacherId($u);
            $q->where(fn ($w) => $w->where('requested_by', $u->id)->when($tid, fn ($x) => $x->orWhere('teacher_id', $tid)));
        }
        $request->filled('status') && $q->where('status', $request->input('status'));

        return response()->json($q->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'student_id' => ['required', ResourceRegistry::existsInSchool('students')], 'teacher_id' => ['nullable', ResourceRegistry::existsInSchool('teachers')],
            'topic' => ['required', 'string', 'max:200'], 'details' => ['nullable', 'string', 'max:3000'], 'preferred_at' => ['nullable', 'date', 'after:now'],
        ]);
        // Only for a child with an APPROVED link: knowing a student id grants nothing.
        abort_unless(in_array($d['student_id'], $this->access->studentIds($request->user()), true) && ! $this->access->ownStudentId($request->user()), 403, 'این دانش‌آموز فرزند تأییدشدهٔ شما نیست.');

        $m = MeetingRequest::create($d + ['requested_by' => $request->user()->id]);
        $targets = $m->teacher_id ? [Teacher::find($m->teacher_id)->user_id] : \Illuminate\Support\Facades\DB::table('school_user_memberships as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.school_id', app(CurrentSchool::class)->id())->whereIn('r.key', ['school_admin', 'deputy'])->pluck('m.user_id')->all();
        $this->notify->send($targets, 'meeting.requested', "meeting:{$m->id}", 'درخواست جلسه: '.$m->topic, null, ['meeting_id' => $m->id]);

        return response()->json(['data' => $m], 201);
    }

    public function respond(Request $request, int $id): JsonResponse
    {
        $m = MeetingRequest::findOrFail($id);
        $tid = $this->access->teacherId($request->user());
        abort_unless($this->access->isStaff($request->user()) || ($tid && $m->teacher_id === $tid), 403);
        $d = $request->validate(['status' => ['required', Rule::in(['accepted', 'declined', 'done'])], 'scheduled_at' => ['nullable', 'date', 'required_if:status,accepted'], 'response_note' => ['nullable', 'string', 'max:500']]);
        $m->update($d + ['handled_by' => $request->user()->id]);
        $this->notify->send([$m->requested_by], 'meeting.responded', "meeting-resp:{$m->id}:{$m->status}", $m->status === 'accepted' ? 'جلسه تأیید شد' : 'پاسخ به درخواست جلسه', $m->response_note, ['meeting_id' => $m->id]);
        Audit::record('meeting.'.$m->status, $m);

        return response()->json(['data' => $m]);
    }
}

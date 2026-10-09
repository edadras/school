<?php

namespace App\Modules\Attendance\Http;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\LessonSession;
use App\Models\Student;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Attendance\AttendanceService;
use App\Modules\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $svc, private Access $access) {}

    /** Roll call for a live/online session. */
    public function recordForSession(Request $request, int $sessionId): JsonResponse
    {
        $s = LessonSession::findOrFail($sessionId);
        abort_unless($this->access->canTeach($request->user(), $s->section_id, $s->subject_id), 403);
        $items = $this->items($request);
        $n = $this->svc->recordManual($request->user(), $s->section_id, $s->subject_id, AttendanceService::keyFor($s), $s->on_date->toDateString(), $items, $s->id);

        return response()->json(['updated' => $n]);
    }

    /** Roll call for an in-person lesson of the timetable (no online session). */
    public function recordForEntry(Request $request): JsonResponse
    {
        $d = $request->validate(['timetable_entry_id' => ['required', ResourceRegistry::existsInSchool('timetable_entries')], 'date' => ['required', 'date', 'before_or_equal:today']]);
        $e = \App\Models\TimetableEntry::findOrFail($d['timetable_entry_id']);
        abort_unless($this->access->canTeach($request->user(), $e->section_id, $e->subject_id), 403);
        $n = $this->svc->recordManual($request->user(), $e->section_id, $e->subject_id, "e{$e->id}:{$d['date']}", $d['date'], $this->items($request));

        return response()->json(['updated' => $n]);
    }

    /** List with server-side filters, scoped to what the caller may see. */
    public function index(Request $request): JsonResponse
    {
        $q = AttendanceRecord::query()->orderByDesc('on_date')->orderByDesc('id');
        $user = $request->user();
        if (! $this->access->can($user, 'attendance.view_all')) {
            $secs = $this->access->sectionIds($user);
            $q->whereIn('section_id', $secs ?? []);
            if (! $this->access->can($user, 'attendance.take')) {      // student / guardian: only own children
                $q->whereIn('student_id', $this->access->studentIds($user));
            }
        }
        foreach (['section_id', 'student_id', 'status'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }
        $request->filled('from') && $q->whereDate('on_date', '>=', $request->input('from'));
        $request->filled('to') && $q->whereDate('on_date', '<=', $request->input('to'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }

    public function summary(Request $request, int $studentId): JsonResponse
    {
        Student::findOrFail($studentId);
        abort_unless($this->access->canViewStudent($request->user(), $studentId), 404);

        return response()->json(['data' => $this->svc->summary($studentId, $request->input('from'), $request->input('to'))]);
    }

    private function items(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.student_id' => ['required', 'integer'], 'items.*.status' => ['required', Rule::in(['present', 'late', 'absent', 'excused'])],
            'items.*.minutes_late' => ['nullable', 'integer', 'between:0,240'], 'items.*.note' => ['nullable', 'string', 'max:255'],
        ])['items'];
    }
}

<?php

namespace App\Modules\Scheduling\Http;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Scheduling\TimetableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimetableController extends Controller
{
    public function __construct(private TimetableService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Timetable::orderByDesc('id')->paginate(25)]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Timetable::with(['periods', 'entries'])->findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', ResourceRegistry::existsInSchool('academic_years')],
            'title' => ['required', 'string', 'max:80'],
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:0,6', 'distinct'],
            'periods' => ['required', 'array', 'min:1', 'max:20'],
            'periods.*.kind' => ['required', Rule::in(['lesson', 'break', 'prep'])],
            'periods.*.title' => ['required', 'string', 'max:60'],
            'periods.*.starts_at' => ['required', 'date_format:H:i'],
            'periods.*.ends_at' => ['required', 'date_format:H:i'],
        ]);
        $periods = $data['periods'];
        unset($data['periods']);

        return response()->json(['data' => $this->service->create($data, $periods)], 201);
    }

    /** Pre-save conflict check (no write). */
    public function check(Request $request, int $id): JsonResponse
    {
        $conflicts = $this->service->conflictsFor(Timetable::findOrFail($id), $this->entryData($request));

        return response()->json(['ok' => $conflicts === [], 'conflicts' => $conflicts]);
    }

    public function addEntry(Request $request, int $id): JsonResponse
    {
        $entry = $this->service->addEntry(Timetable::findOrFail($id), $this->entryData($request));

        return response()->json(['data' => $entry], 201);
    }

    public function removeEntry(int $id, int $entryId): JsonResponse
    {
        $tt = Timetable::findOrFail($id);
        $this->service->removeEntry($tt, TimetableEntry::where('timetable_id', $id)->findOrFail($entryId));

        return response()->json(null, 204);
    }

    public function activate(int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->activate(Timetable::findOrFail($id))]);
    }

    public function substitute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'timetable_entry_id' => ['required', ResourceRegistry::existsInSchool('timetable_entries')],
            'on_date' => ['required', 'date', 'after_or_equal:today'],
            'status' => ['required', Rule::in(['substitute', 'cancelled'])],
            'substitute_teacher_id' => ['nullable', ResourceRegistry::existsInSchool('teachers')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $sub = $this->service->substitute(TimetableEntry::findOrFail($data['timetable_entry_id']), $data['on_date'], $data['substitute_teacher_id'] ?? null, $data['status'], $data['reason'] ?? null);

        return response()->json(['data' => $sub], 201);
    }

    /** Own weekly schedule for teacher or student (permission: schedule.own). */
    public function mine(Request $request): JsonResponse
    {
        $tt = Timetable::where('status', 'active')->first();
        if (! $tt) {
            return response()->json(['timetable' => null, 'entries' => []]);
        }
        $uid = $request->user()->id;
        $q = TimetableEntry::where('timetable_id', $tt->id);

        if ($teacher = Teacher::where('user_id', $uid)->first()) {
            $q->where('teacher_id', $teacher->id);
        } elseif ($student = Student::where('user_id', $uid)->first()) {
            $q->whereIn('section_id', \App\Models\Enrollment::where('student_id', $student->id)->where('status', 'active')->select('section_id'));
        } else {
            $q->whereRaw('1 = 0');
        }

        return response()->json([
            'timetable' => $tt->only(['id', 'title', 'version', 'working_days']),
            'periods' => $tt->periods,
            'entries' => $q->get(),
            'server_time' => now()->toIso8601String(), // clients align their clock to the server
        ]);
    }

    private function entryData(Request $request): array
    {
        return $request->validate([
            'period_id' => ['required', ResourceRegistry::existsInSchool('timetable_periods')],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'teacher_id' => ['required', ResourceRegistry::existsInSchool('teachers')],
        ]);
    }
}

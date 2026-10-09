<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Teacher ↔ (section, subject) assignment with conflict checks. */
class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = TeacherAssignment::query()->orderByDesc('id');
        foreach (['teacher_id', 'section_id', 'subject_id'] as $f) {
            $request->filled($f) && $q->where($f, $request->integer($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'teacher_id' => ['required', ResourceRegistry::existsInSchool('teachers')],
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'weekly_hours' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $section = Section::findOrFail($data['section_id']);
        $subject = Subject::findOrFail($data['subject_id']);

        if ($subject->grade_id && $subject->grade_id !== $section->grade_id) {
            throw ValidationException::withMessages(['subject_id' => ['این درس به پایه‌ی این کلاس تعلق ندارد.']]);
        }
        $taken = TeacherAssignment::where('section_id', $section->id)->where('subject_id', $subject->id)->first();
        if ($taken) {
            throw ValidationException::withMessages(['subject_id' => ['برای این درس در این کلاس قبلاً معلم تعیین شده است.']]);
        }

        $assignment = TeacherAssignment::create($data);
        Audit::record('assignment.created', $assignment, null, $data);

        return response()->json(['data' => $assignment], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $a = TeacherAssignment::findOrFail($id);
        if (\DB::table('timetable_entries')->where('section_id', $a->section_id)->where('subject_id', $a->subject_id)->where('teacher_id', $a->teacher_id)->exists()) {
            return response()->json(['message' => 'این تخصیص در برنامه هفتگی استفاده شده است.', 'code' => 'delete_blocked'], 409);
        }
        $a->delete();
        Audit::record('assignment.deleted', $a, $a->toArray());

        return response()->json(null, 204);
    }
}

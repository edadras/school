<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Section;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Enrollment::query()->orderByDesc('id');
        $request->filled('section_id') && $q->where('section_id', $request->integer('section_id'));
        $request->filled('student_id') && $q->where('student_id', $request->integer('student_id'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }

    /** Enroll, or transfer if the student already has an enrollment in that academic year. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', ResourceRegistry::existsInSchool('students')],
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
        ]);

        $result = DB::transaction(function () use ($data) {
            // Lock the section row so concurrent enrolments can't overshoot capacity.
            $section = Section::whereKey($data['section_id'])->lockForUpdate()->firstOrFail();
            $existing = Enrollment::where('student_id', $data['student_id'])->where('academic_year_id', $section->academic_year_id)->first();

            if ($existing && $existing->section_id === $section->id && $existing->status === 'active') {
                return [$existing, 200];
            }

            $active = Enrollment::where('section_id', $section->id)->where('status', 'active')->count();
            if ($active >= $section->capacity) {
                throw ValidationException::withMessages(['section_id' => ['ظرفیت کلاس تکمیل است.']]);
            }

            if ($existing) {
                $old = $existing->only(['section_id', 'status']);
                $existing->update(['section_id' => $section->id, 'status' => 'active', 'ended_on' => null]);
                Audit::record('enrollment.transferred', $existing, $old, ['section_id' => $section->id]);

                return [$existing, 200];
            }

            $enrollment = Enrollment::create([
                'student_id' => $data['student_id'], 'section_id' => $section->id,
                'academic_year_id' => $section->academic_year_id, 'enrolled_on' => today(),
            ]);
            Audit::record('enrollment.created', $enrollment, null, $data);

            return [$enrollment, 201];
        });

        return response()->json(['data' => $result[0]], $result[1]);
    }
}

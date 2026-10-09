<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Modules\Tenancy\Access;

class ResourceController extends Controller
{
    /** Teachers only see rows tied to their own classes; students/guardians have no access to these endpoints at all. */
    private function scoped(Request $request, string $resource, $q)
    {
        $access = app(Access::class);
        $user = $request->user();
        if ($access->isStaff($user)) {
            return $q;
        }
        $sections = $access->sectionIds($user) ?? [];
        $teacherId = $access->teacherId($user) ?? 0;

        return match ($resource) {
            'sections' => $q->whereIn('id', $sections),
            'students' => $q->whereIn('id', \App\Models\Enrollment::whereIn('section_id', $sections)->where('status', 'active')->select('student_id')),
            'grades' => $q->whereIn('id', \App\Models\Section::whereIn('id', $sections)->select('grade_id')),
            'subjects' => $q->whereIn('id', \App\Models\TeacherAssignment::where('teacher_id', $teacherId)->select('subject_id')),
            default => $q,   // academic years, calendar events: school-wide by nature
        };
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $q = $this->scoped($request, $resource, ResourceRegistry::model($resource)::query())->orderByDesc('id');
        $resource === 'sections' && $q->with('grade:id,name');
        // Students of one class (active enrolment) — used by roll call, grade entry and exam grading.
        $resource === 'students' && $request->filled('section_id')
            && $q->whereIn('id', \App\Models\Enrollment::where('section_id', $request->integer('section_id'))->where('status', 'active')->select('student_id'));

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')).'%';
            $cols = ['academic-years' => ['title'], 'terms' => ['title'], 'grades' => ['name'], 'subjects' => ['name', 'code'],
                'sections' => ['name'], 'students' => ['first_name', 'last_name', 'student_code'], 'calendar-events' => ['title']][$resource];
            $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, 'like', $term)));
        }
        $filterable = array_intersect(['grade_id', 'academic_year_id', 'status'], array_keys(ResourceRegistry::rules($resource, true)));
        foreach ($filterable as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        return response()->json(['data' => $this->scoped($request, $resource, ResourceRegistry::model($resource)::query())->findOrFail($id)]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $data = $request->validate(ResourceRegistry::rules($resource, false));

        $resource === 'students' && $this->enforceStudentLimit();

        $model = DB::transaction(function () use ($resource, $data) {
            $model = ResourceRegistry::model($resource)::create($data);
            $this->afterSave($resource, $model);

            return $model;
        });
        Audit::record("$resource.created", $model, null, $model->only(array_keys($data)));

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $model = ResourceRegistry::model($resource)::findOrFail($id);
        $data = $request->validate(ResourceRegistry::rules($resource, true, $id));
        $old = $model->only(array_keys($data));

        DB::transaction(function () use ($resource, $model, $data) {
            $model->update($data);
            $this->afterSave($resource, $model);
        });
        Audit::record("$resource.updated", $model, $old, $data);

        return response()->json(['data' => $model->refresh()]);
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $model = ResourceRegistry::model($resource)::findOrFail($id);

        if ($blocker = ResourceRegistry::deleteBlocker($resource, $model)) {
            return response()->json(['message' => $blocker, 'code' => 'delete_blocked'], 409);
        }
        $model->delete();
        Audit::record("$resource.deleted", $model, $model->toArray());

        return response()->json(null, 204);
    }

    private function afterSave(string $resource, $model): void
    {
        // Exactly one current academic year per school.
        if ($resource === 'academic-years' && $model->is_current) {
            $model::query()->where('id', '!=', $model->id)->update(['is_current' => false]);
        }
    }

    private function enforceStudentLimit(): void
    {
        $max = DB::table('school_subscriptions')->where('school_id', app(\App\Modules\Tenancy\CurrentSchool::class)->id())
            ->where('status', 'active')->value('max_students');

        if ($max !== null && \App\Models\Student::where('status', 'active')->count() >= $max) {
            throw \Illuminate\Validation\ValidationException::withMessages(['limit' => ['سقف تعداد دانش‌آموزان طرح اشتراک پر شده است.']]);
        }
    }
}

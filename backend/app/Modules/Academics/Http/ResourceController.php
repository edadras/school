<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceController extends Controller
{
    public function index(Request $request, string $resource): JsonResponse
    {
        $q = ResourceRegistry::model($resource)::query()->orderByDesc('id');

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')).'%';
            $cols = ['academic-years' => ['title'], 'grades' => ['name'], 'subjects' => ['name', 'code'],
                'sections' => ['name'], 'students' => ['first_name', 'last_name', 'student_code'], 'calendar-events' => ['title']][$resource];
            $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, 'like', $term)));
        }
        $filterable = array_intersect(['grade_id', 'academic_year_id', 'status'], array_keys(ResourceRegistry::rules($resource, true)));
        foreach ($filterable as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function show(string $resource, int $id): JsonResponse
    {
        return response()->json(['data' => ResourceRegistry::model($resource)::findOrFail($id)]);
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

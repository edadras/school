<?php

namespace App\Modules\Learning\Http;

use App\Http\Controllers\Controller;
use App\Models\LearningMaterial;
use App\Models\StoredFile;
use App\Modules\Academics\ResourceRegistry;
use App\Modules\Audit\Audit;
use App\Modules\Files\FileService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function __construct(private Access $access, private FileService $files) {}

    /** Students/guardians see published materials of their sections only; teachers their own classes; staff all. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = LearningMaterial::query()->orderByDesc('id');
        $sections = $this->access->sectionIds($user);
        $sections !== null && $q->whereIn('section_id', $sections);
        if (! $this->access->can($user, 'content.manage') && ! $this->access->isStaff($user)) {
            $q->whereNotNull('published_at')->where('published_at', '<=', now());
        }
        foreach (['section_id', 'subject_id'] as $f) {
            $request->filled($f) && $q->where($f, $request->integer($f));
        }

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'section_id' => ['required', ResourceRegistry::existsInSchool('sections')],
            'subject_id' => ['required', ResourceRegistry::existsInSchool('subjects')],
            'title' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(['file', 'link', 'text'])],
            'body' => ['nullable', 'string', 'max:50000', Rule::requiredIf(fn () => in_array($request->input('kind'), ['link', 'text'], true))],
            'file_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $request->input('kind') === 'file')],
            'ai_indexable' => ['sometimes', 'boolean'],
            'publish' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->access->canTeach($request->user(), $d['section_id'], $d['subject_id']), 403, 'شما معلم این درس در این کلاس نیستید.');
        if ($d['kind'] === 'link' && ! preg_match('#^https?://#i', (string) $d['body'])) {
            return response()->json(['message' => 'نشانی پیوند معتبر نیست.', 'errors' => ['body' => ['نشانی پیوند معتبر نیست.']]], 422);
        }
        $teacherId = $this->access->teacherId($request->user())
            ?? \App\Models\TeacherAssignment::where('section_id', $d['section_id'])->where('subject_id', $d['subject_id'])->value('teacher_id');
        abort_unless($teacherId, 422, 'معلمی برای این درس تعیین نشده است.');

        $publish = (bool) ($d['publish'] ?? true);
        unset($d['publish']);
        $m = LearningMaterial::create($d + ['teacher_id' => $teacherId, 'published_at' => $publish ? now() : null]);
        if (! empty($d['file_id'])) {
            $this->files->claim($d['file_id'], $request->user(), 'material', $m->id);
        }
        if ($publish) {
            app(NotificationService::class)->send(Recipients::forSection(app(CurrentSchool::class)->id(), $m->section_id), 'material.new',
                "material:{$m->id}", 'محتوای جدید: '.$m->title, null, ['material_id' => $m->id]);
        }
        Audit::record('material.created', $m, null, ['title' => $m->title]);

        return response()->json(['data' => $m], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::findOrFail($id);
        abort_unless($this->access->canTeach($request->user(), $m->section_id, $m->subject_id), 403);
        $m->delete();
        Audit::record('material.deleted', $m, $m->toArray());

        return response()->json(null, 204);
    }
}

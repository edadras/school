<?php

namespace App\Modules\Administration\Http;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\School;
use App\Models\StudentNote;
use App\Modules\Audit\Audit;
use App\Modules\Files\SettingsRepository;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolAdminController extends Controller
{
    private const ALLOWED_SETTINGS = [
        'messaging.enabled' => 'boolean', 'messaging.quiet_hours' => 'array', 'messaging.block_during_lessons' => 'boolean',
        'messaging.student_to_student' => 'boolean', 'messaging.student_to_teacher' => 'boolean', 'messaging.guardian_to_teacher' => 'boolean',
        'attendance.late_after_minutes' => 'integer', 'recording.allowed' => 'boolean', 'files.max_mb' => 'integer',
        'ai.enabled' => 'boolean', 'ai.student_enabled' => 'boolean', 'ai.student_min_grade_level' => 'integer',
        'ai.daily_limit_student' => 'integer', 'ai.daily_limit_teacher' => 'integer', 'ai.daily_token_budget' => 'integer',
        'custom_fields.students' => 'array',
        'retention.messages_days' => 'integer', 'retention.notifications_days' => 'integer', 'retention.ai_requests_days' => 'integer',
        'retention.whiteboard_days' => 'integer', 'retention.tickets_days' => 'integer',
    ];

    public function __construct(private SettingsRepository $settings, private Access $access) {}

    public function profile(): JsonResponse
    {
        $s = School::with('subscription')->find(app(CurrentSchool::class)->id());

        return response()->json(['data' => $s->only(['id', 'code', 'name', 'logo_path', 'phone', 'email', 'address', 'city', 'timezone', 'calendar', 'locale', 'status']) + ['subscription' => $s->subscription],
            'settings' => (object) $this->settings->all()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'], 'timezone' => ['sometimes', 'timezone:all'], 'calendar' => ['sometimes', Rule::in(['jalali', 'gregorian'])], 'locale' => ['sometimes', Rule::in(['fa', 'en', 'tr'])]]);
        $s = School::find(app(CurrentSchool::class)->id());
        $old = $s->only(array_keys($d));
        $s->update($d);
        Audit::record('school.profile_updated', $s, $old, $d);

        return response()->json(['data' => $s]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $input = $request->validate(['settings' => ['required', 'array']])['settings'];
        $changed = [];
        foreach ($input as $key => $value) {
            abort_unless(isset(self::ALLOWED_SETTINGS[$key]), 422, "تنظیم نامعتبر: $key");
            $type = self::ALLOWED_SETTINGS[$key];
            $ok = match ($type) { 'boolean' => is_bool($value), 'integer' => is_int($value) && $value >= 0, 'array' => is_array($value) };
            abort_unless($ok, 422, "مقدار نامعتبر برای $key");
            abort_if(str_starts_with($key, 'retention.') && $value !== 0 && $value < 30, 422, 'حداقل دورهٔ نگهداری ۳۰ روز است (۰ = نگهداری دائم).');
            $this->settings->set($key, $value);
            $changed[$key] = $value;
        }
        Audit::record('school.settings_changed', null, null, $changed);

        return response()->json(['settings' => (object) $this->settings->all()]);
    }

    public function audit(Request $request): JsonResponse
    {
        $q = AuditLog::where('school_id', app(CurrentSchool::class)->id())->orderByDesc('id');
        $request->filled('action') && $q->where('action', 'like', $request->string('action').'%');
        $request->filled('user_id') && $q->where('user_id', $request->integer('user_id'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }

    // ---- announcements / official messages
    public function announcements(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Announcement::query()->whereNotNull('published_at')->where('published_at', '<=', now())->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('published_at');
        if (! $this->access->can($user, 'announcements.manage')) {
            $secs = $this->access->sectionIds($user) ?? [];
            $grades = \App\Models\Section::whereIn('id', $secs)->pluck('grade_id');
            $studentIds = $this->access->studentIds($user);
            $q->where(fn ($w) => $w->where('audience_type', 'school')
                ->orWhere(fn ($x) => $x->where('audience_type', 'grade')->whereIn('audience_id', $grades))
                ->orWhere(fn ($x) => $x->where('audience_type', 'section')->whereIn('audience_id', $secs))
                ->orWhere(fn ($x) => $x->where('audience_type', 'student')->whereIn('audience_id', $studentIds)));
        }

        return response()->json($q->paginate(25));
    }

    public function publishAnnouncement(Request $request, NotificationService $notify): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:10000'],
            'audience_type' => ['required', Rule::in(['school', 'grade', 'section', 'student'])], 'audience_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $request->input('audience_type') !== 'school')],
            'expires_at' => ['nullable', 'date', 'after:now']]);
        $schoolId = app(CurrentSchool::class)->id();
        if ($d['audience_type'] !== 'school') {
            $table = ['grade' => 'grades', 'section' => 'sections', 'student' => 'students'][$d['audience_type']];
            abort_unless(DB::table($table)->where('school_id', $schoolId)->where('id', $d['audience_id'])->exists(), 422, 'مخاطب نامعتبر است.');
        }
        $a = Announcement::create($d + ['author_id' => $request->user()->id, 'published_at' => now()]);

        $users = match ($d['audience_type']) {
            'school' => DB::table('school_user_memberships')->where('school_id', $schoolId)->where('status', 'active')->pluck('user_id'),
            'grade' => collect(\App\Models\Section::where('grade_id', $d['audience_id'])->pluck('id'))->flatMap(fn ($sid) => $this->sectionAudience($sid)),
            'section' => collect($this->sectionAudience($d['audience_id'])),
            'student' => collect([\App\Models\Student::find($d['audience_id'])->user_id])->merge(Recipients::guardianUsers($d['audience_id']))->filter(),
        };
        $notify->send($users->unique(), 'announcement', "announcement:{$a->id}", $a->title, str($a->body)->limit(120)->toString(), ['announcement_id' => $a->id]);
        Audit::record('announcement.published', $a, null, ['audience' => $d['audience_type']]);

        return response()->json(['data' => $a, 'recipients' => $users->unique()->count()], 201);
    }

    private function sectionAudience(int $sectionId): array
    {
        $teachers = DB::table('teacher_assignments as ta')->join('teachers as t', 't.id', '=', 'ta.teacher_id')->where('ta.section_id', $sectionId)->pluck('t.user_id');

        return collect(Recipients::forSection(app(CurrentSchool::class)->id(), $sectionId))->merge($teachers)->unique()->values()->all();
    }

    // ---- discipline / praise / counselling notes
    public function notes(Request $request, int $studentId): JsonResponse
    {
        abort_unless($this->access->canViewStudent($request->user(), $studentId), 404);
        $q = StudentNote::where('student_id', $studentId)->orderByDesc('id');
        if (! $this->access->isStaff($request->user())) {
            $q->where(fn ($w) => $w->where('author_id', $request->user()->id)->orWhere('visible_to_guardian', true));
            in_array($studentId, $this->access->studentIds($request->user()), true) && ! $this->access->can($request->user(), 'grades.enter') && $q->where('visible_to_guardian', true)->whereNotIn('kind', ['counselling']);
        }
        $this->access->isStaff($request->user()) && Audit::record('student_notes.viewed', null, null, ['student_id' => $studentId]);

        return response()->json(['data' => $q->get()]);
    }

    public function addNote(Request $request, int $studentId): JsonResponse
    {
        abort_unless($this->access->canViewStudent($request->user(), $studentId) && ($this->access->isStaff($request->user()) || $this->access->can($request->user(), 'grades.enter')), 403);
        $d = $request->validate(['kind' => ['required', Rule::in(['discipline', 'praise', 'counselling', 'strength', 'need'])], 'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:5000'], 'visible_to_guardian' => ['sometimes', 'boolean']]);
        abort_if($d['kind'] === 'counselling' && ! $this->access->isStaff($request->user()), 403, 'ثبت مشاوره فقط برای کادر مدرسه است.');
        $n = StudentNote::create($d + ['student_id' => $studentId, 'author_id' => $request->user()->id]);
        Audit::record('student_note.created', $n, null, ['kind' => $d['kind'], 'student_id' => $studentId]);

        return response()->json(['data' => $n], 201);
    }
}

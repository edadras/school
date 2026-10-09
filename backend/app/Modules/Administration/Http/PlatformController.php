<?php

namespace App\Modules\Administration\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LessonSession;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\StoredFile;
use App\Models\User;
use App\Modules\AI\AiProvider;
use App\Modules\Audit\Audit;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PlatformController extends Controller
{
    /** Per-school usage: users, live sessions, storage, AI. */
    public function stats(Request $request): JsonResponse
    {
        $schools = School::withoutGlobalScopes()->orderBy('id')->get(['id', 'code', 'name', 'status']);
        $users = DB::table('school_user_memberships')->where('status', 'active')->selectRaw('school_id, count(distinct user_id) c')->groupBy('school_id')->pluck('c', 'school_id');
        $students = DB::table('students')->whereNull('deleted_at')->selectRaw('school_id, count(*) c')->groupBy('school_id')->pluck('c', 'school_id');
        $storage = StoredFile::withoutGlobalScopes()->selectRaw('school_id, sum(size) s')->groupBy('school_id')->pluck('s', 'school_id');
        $live = LessonSession::withoutGlobalScopes()->where('status', 'live')->selectRaw('school_id, count(*) c')->groupBy('school_id')->pluck('c', 'school_id');
        $sessions30 = LessonSession::withoutGlobalScopes()->whereDate('on_date', '>=', now()->subDays(30))->selectRaw('school_id, count(*) c')->groupBy('school_id')->pluck('c', 'school_id');
        $ai = DB::table('ai_usage_records')->where('on_date', '>=', now()->subDays(30)->toDateString())->selectRaw('school_id, sum(requests) r, sum(cost_micro) c')->groupBy('school_id')->get()->keyBy('school_id');
        $subs = DB::table('school_subscriptions')->where('status', 'active')->get()->keyBy('school_id');

        return response()->json([
            'totals' => ['schools' => $schools->count(), 'by_status' => (object) $schools->groupBy('status')->map->count()->all(), 'users' => (int) $users->sum(), 'students' => (int) $students->sum(),
                'live_sessions' => (int) $live->sum(), 'storage_bytes' => (int) $storage->sum()],
            'schools' => $schools->map(fn ($s) => $s->only(['id', 'code', 'name', 'status']) + [
                'users' => (int) ($users[$s->id] ?? 0), 'students' => (int) ($students[$s->id] ?? 0), 'live_sessions' => (int) ($live[$s->id] ?? 0), 'sessions_30d' => (int) ($sessions30[$s->id] ?? 0),
                'storage_bytes' => (int) ($storage[$s->id] ?? 0), 'ai_requests_30d' => (int) ($ai[$s->id]->r ?? 0), 'plan' => $subs[$s->id]->plan ?? null,
                'limits' => isset($subs[$s->id]) ? ['students' => $subs[$s->id]->max_students, 'teachers' => $subs[$s->id]->max_teachers, 'live_sessions' => $subs[$s->id]->max_live_sessions, 'storage_mb' => $subs[$s->id]->max_storage_mb] : null,
            ])->values(),
        ]);
    }

    /** Service health: database, cache, storage, queue backlog + failures, media (SFU), AI. */
    public function health(MediaProvider $media, AiProvider $ai): JsonResponse
    {
        $check = function (callable $fn): array {
            try {
                return $fn();
            } catch (\Throwable $e) {
                return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 160)];
            }
        };
        $out = [
            'database' => $check(fn () => ['ok' => (bool) DB::select('select 1'), 'detail' => DB::getDriverName()]),
            'cache' => $check(function () {
                Cache::put('health', 1, 5);

                return ['ok' => Cache::get('health') === 1, 'detail' => config('cache.default')];
            }),
            'storage' => $check(fn () => ['ok' => Storage::disk(config('files.disk'))->exists('.') || true, 'detail' => config('files.disk')]),
            'queue' => $check(fn () => ['ok' => true, 'detail' => config('queue.default'), 'failed_jobs' => (int) DB::table('failed_jobs')->count(),
                'pending_jobs' => config('queue.default') === 'database' ? (int) DB::table('jobs')->count() : null]),
            'media' => $media->isConfigured() ? $check(fn () => $media->health()) : ['ok' => false, 'detail' => 'unconfigured', 'configured' => false],
            'ai' => ['ok' => $ai->isConfigured(), 'detail' => $ai->isConfigured() ? $ai->name() : 'unconfigured', 'configured' => $ai->isConfigured()],
            'push' => ['ok' => (new \App\Modules\Notifications\Channels\PushChannel)->isConfigured(), 'detail' => 'fcm'],
            'realtime' => ['ok' => config('broadcasting.default') !== 'null', 'detail' => config('broadcasting.default')],
        ];
        $out['bell_last_tick'] = Cache::get('bell:last_tick');

        return response()->json($out);
    }

    // ---- platform announcements (fan out to every school admin)
    public function announcements(): JsonResponse
    {
        return response()->json(['data' => PlatformAnnouncement::orderByDesc('id')->limit(50)->get()]);
    }

    public function publishAnnouncement(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:10000']]);
        $a = PlatformAnnouncement::create($d + ['author_id' => $request->user()->id, 'published_at' => now()]);
        $n = 0;
        School::where('status', 'active')->each(function (School $school) use ($a, &$n) {
            app(\App\Modules\Tenancy\CurrentSchool::class)->run($school, function () use ($a, $school, &$n) {
                $admins = DB::table('school_user_memberships as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.school_id', $school->id)->where('r.key', 'school_admin')->pluck('m.user_id');
                $n += app(\App\Modules\Notifications\NotificationService::class)->send($admins, 'platform.announcement', "platform-ann:{$a->id}", $a->title, str($a->body)->limit(120)->toString(), ['announcement_id' => $a->id]);
            });
        });
        Audit::record('platform.announcement', $a, null, ['title' => $a->title]);

        return response()->json(['data' => $a, 'notified' => $n], 201);
    }

    // ---- platform operators (super admins and support staff)
    public function operators(): JsonResponse
    {
        return response()->json(['data' => User::whereNotNull('platform_role')->get(['id', 'name', 'email', 'platform_role', 'status', 'last_login_at'])]);
    }

    public function createOperator(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'role' => ['required', Rule::in(['super_admin', 'support'])],
            'password' => ['required', Password::min(12)->letters()->numbers()]]);
        $u = new User(['name' => $d['name'], 'email' => $d['email'], 'password' => $d['password']]);
        $u->forceFill(['platform_role' => $d['role']])->save();
        Audit::record('platform.operator_created', $u, null, ['role' => $d['role']]);

        return response()->json(['data' => $u->only(['id', 'name', 'email', 'platform_role'])], 201);
    }

    public function updateOperator(Request $request, int $id): JsonResponse
    {
        $u = User::whereNotNull('platform_role')->findOrFail($id);
        abort_if($u->id === $request->user()->id, 422, 'نمی‌توانید حساب خودتان را تغییر دهید.');
        $d = $request->validate(['role' => ['sometimes', Rule::in(['super_admin', 'support'])], 'status' => ['sometimes', Rule::in(['active', 'disabled'])]]);
        $old = $u->only(['platform_role', 'status']);
        isset($d['role']) && $u->platform_role = $d['role'];
        isset($d['status']) && $u->status = $d['status'];
        $u->save();
        ($d['status'] ?? null) === 'disabled' && $u->tokens()->delete();
        Audit::record('platform.operator_updated', $u, $old, $d);

        return response()->json(['data' => $u->only(['id', 'name', 'platform_role', 'status'])]);
    }

    /** Latency/error counters recorded by RecordMetrics (last N hours, default 24). */
    public function metrics(Request $request): JsonResponse
    {
        $hours = min(max($request->integer('hours', 24), 1), 48);
        $series = [];
        $routes = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $h = now()->subHours($i)->format('YmdH');
            $n = (int) \Cache::get("m:h:$h:n", 0);
            $series[] = ['hour' => $h, 'requests' => $n, 'errors_5xx' => (int) \Cache::get("m:h:$h:e5", 0), 'errors_4xx' => (int) \Cache::get("m:h:$h:e4", 0),
                'avg_ms' => $n ? round(\Cache::get("m:h:$h:ms", 0) / $n, 1) : null,
                'slow' => collect(\App\Http\Middleware\RecordMetrics::BUCKETS)->mapWithKeys(fn ($b) => ["gt_{$b}ms" => (int) \Cache::get("m:h:$h:gt$b", 0)])];
            foreach (\Cache::get("m:routes:$h", []) as $r) {
                $rn = (int) \Cache::get("m:r:$h:$r:n", 0);
                $routes[$r]['n'] = ($routes[$r]['n'] ?? 0) + $rn;
                $routes[$r]['ms'] = ($routes[$r]['ms'] ?? 0) + (int) \Cache::get("m:r:$h:$r:ms", 0);
                $routes[$r]['e5'] = ($routes[$r]['e5'] ?? 0) + (int) \Cache::get("m:r:$h:$r:e5", 0);
            }
        }
        $top = collect($routes)->map(fn ($v, $k) => ['route' => $k, 'requests' => $v['n'], 'avg_ms' => $v['n'] ? round($v['ms'] / $v['n'], 1) : null, 'errors_5xx' => $v['e5']])
            ->sortByDesc('avg_ms')->take(15)->values();

        return response()->json(['hours' => $series, 'slowest_routes' => $top, 'queue_backlog' => (int) \Illuminate\Support\Facades\DB::table('jobs')->count(), 'failed_jobs' => (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->count()]);
    }

    // ---- platform settings
    public function settings(): JsonResponse
    {
        return response()->json(['data' => (object) PlatformSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['settings' => ['required', 'array'], 'settings.default_timezone' => ['sometimes', 'timezone:all'], 'settings.default_locale' => ['sometimes', Rule::in(['fa', 'en', 'tr'])],
            'settings.default_plan' => ['sometimes', 'array'], 'settings.registration_open' => ['sometimes', 'boolean'], 'settings.maintenance_banner' => ['nullable', 'string', 'max:300']]);
        foreach ($d['settings'] as $k => $v) {
            PlatformSetting::updateOrCreate(['key' => $k], ['value' => is_array($v) ? $v : [$v]]);
        }
        Audit::record('platform.settings_changed', null, null, $d['settings']);

        return $this->settings();
    }

    public function audit(Request $request): JsonResponse
    {
        $q = AuditLog::query()->orderByDesc('id');
        $request->filled('school_id') && $q->where('school_id', $request->integer('school_id'));
        $request->filled('action') && $q->where('action', 'like', $request->string('action').'%');

        return response()->json($q->paginate(min((int) $request->integer('per_page', 50), 200)));
    }
}

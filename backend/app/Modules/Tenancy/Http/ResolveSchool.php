<?php

namespace App\Modules\Tenancy\Http;

use App\Models\School;
use App\Modules\Tenancy\CurrentSchool;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the active school for the authenticated user.
 * The X-School-Id header is only a *selector*: it must match one of the user's
 * active memberships on an active school; otherwise 403. Users with exactly one
 * membership don't need the header.
 */
class ResolveSchool
{
    public function __construct(private CurrentSchool $current) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);

        // Platform support: only into a school whose admin granted time-limited access (read-only, audited).
        if ($user->platform_role === 'support') {
            return $this->supportAccess($request, $next);
        }

        $memberships = $user->memberships()->where('status', 'active')
            ->whereHas('school', fn ($q) => $q->where('status', 'active'))
            ->with('school')->get();

        $wanted = $request->header('X-School-Id');
        $pick = $wanted
            ? $memberships->firstWhere('school_id', (int) $wanted)
            : ($memberships->pluck('school_id')->unique()->count() === 1 ? $memberships->first() : null);

        if (! $pick) {
            return response()->json([
                'message' => $wanted ? 'دسترسی به این مدرسه مجاز نیست.' : 'مدرسه فعال مشخص نشده است.',
                'code' => 'school_not_resolved',
            ], 403);
        }

        $this->current->set($pick->school);
        $request->attributes->set('school_roles', $memberships->where('school_id', $pick->school_id)->pluck('role_id')->all());

        return $next($request);
    }

    private function supportAccess(Request $request, Closure $next)
    {
        // Read-only, and only structural data — never grades, attendance, messages, files or reports.
        $allowed = ['academics/', 'teachers', 'teacher-assignments', 'enrollments', 'timetables', 'school/profile', 'media/status'];
        $path = preg_replace('#^api/v1/#', '', $request->path());
        if (! $request->isMethodSafe() || ! collect($allowed)->contains(fn ($p) => str_starts_with($path, $p))) {
            return response()->json(['message' => 'دسترسی پشتیبانی به این بخش مجاز نیست.', 'code' => 'support_access_denied'], 403);
        }
        $schoolId = (int) $request->header('X-School-Id');
        $grant = $schoolId ? \App\Models\SupportAccessGrant::withoutGlobalScopes()->where('school_id', $schoolId)->where('support_user_id', $request->user()->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first() : null;
        $school = $grant ? School::where('id', $schoolId)->where('status', 'active')->first() : null;
        if (! $school) {
            return response()->json(['message' => 'دسترسی پشتیبانی به این مدرسه فعال نیست.', 'code' => 'support_access_denied'], 403);
        }
        $this->current->set($school);
        $request->attributes->set('support_access', true);
        $this->current->run($school, fn () => \App\Modules\Audit\Audit::record('support.data_access', null, null, ['path' => $request->path(), 'grant' => $grant->id]));

        return $next($request);
    }
}

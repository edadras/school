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
}

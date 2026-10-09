<?php

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\CurrentSchool;
use Closure;
use Illuminate\Http\Request;

/** Usage: ->middleware('can.perm:academics.manage'). Checks permission inside the active school. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        $schoolId = app(CurrentSchool::class)->id();

        // Support staff on a granted school: read-only, and only the "view" permissions.
        if ($request->attributes->get('support_access')) {
            $ok = $request->isMethodSafe() && array_intersect($permissions, ['academics.view', 'schedule.view']);

            return $ok ? $next($request) : response()->json(['message' => 'دسترسی پشتیبانی فقط‌خواندنی است.', 'code' => 'forbidden'], 403);
        }

        foreach ($permissions as $permission) {
            if ($user && $schoolId && $user->hasPermissionInSchool($permission, $schoolId)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'شما مجوز انجام این عملیات را ندارید.', 'code' => 'forbidden'], 403);
    }
}

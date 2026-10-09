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

        foreach ($permissions as $permission) {
            if ($user && $schoolId && $user->hasPermissionInSchool($permission, $schoolId)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'شما مجوز انجام این عملیات را ندارید.', 'code' => 'forbidden'], 403);
    }
}

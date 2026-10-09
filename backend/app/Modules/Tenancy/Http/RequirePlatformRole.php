<?php

namespace App\Modules\Tenancy\Http;

use Closure;
use Illuminate\Http\Request;

class RequirePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user || ! in_array($user->platform_role, $roles, true)) {
            return response()->json(['message' => 'دسترسی غیرمجاز.', 'code' => 'forbidden'], 403);
        }

        return $next($request);
    }
}

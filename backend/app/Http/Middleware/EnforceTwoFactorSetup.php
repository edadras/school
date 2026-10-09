<?php

namespace App\Http\Middleware;

use App\Modules\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;

/** When the platform requires 2FA for admins, an admin without it can only reach the enrolment endpoints. */
class EnforceTwoFactorSetup
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user('sanctum');
        if ($u && ! $request->is('api/v1/me/2fa*', 'api/v1/auth/*')) {
            $tf = app(TwoFactor::class);
            if ($tf->requiredFor($u) && ! $tf->enabled($u)) {
                return response()->json(['message' => 'سیاست امنیتی: ابتدا احراز هویت دومرحله‌ای را فعال کنید.', 'code' => 'two_factor_setup_required'], 403);
            }
        }

        return $next($request);
    }
}

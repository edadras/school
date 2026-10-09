<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],   // email or phone
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::where('email', $data['login'])->orWhere('phone', $data['login'])->first();

        // Same message for unknown user / wrong password / disabled: no account enumeration.
        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['اطلاعات ورود نادرست است.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name'] ?? 'web', ['*'], now()->addDays(14));

        $isAdmin = $user->platform_role
            || $user->memberships()->whereHas('role', fn ($q) => $q->where('key', 'school_admin'))->exists();
        if ($isAdmin) {
            $this->actingAs($user);
            Audit::record('auth.admin_login', $user);
        }

        return response()->json(['token' => $token->plainTextToken, 'user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'خروج انجام شد.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Always the same answer so emails can't be enumerated.
        return response()->json(['message' => 'در صورت وجود حساب، لینک بازیابی ارسال شد.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // revoke every session
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['توکن یا ایمیل نامعتبر است.']]);
        }

        return response()->json(['message' => 'رمز عبور تغییر کرد.']);
    }

    private function actingAs(User $user): void
    {
        // The login route is unauthenticated; make the audit record attribute the actor.
        request()->setUserResolver(fn () => $user);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale,
            'platform_role' => $user->platform_role,
            'memberships' => $user->memberships()->where('status', 'active')
                ->with(['school:id,code,name,status', 'role:id,key,name'])->get()
                ->map(fn ($m) => ['school' => $m->school, 'role' => $m->role->key])->values(),
        ];
    }
}

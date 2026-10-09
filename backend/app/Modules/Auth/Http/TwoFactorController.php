<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Modules\Audit\Audit;
use App\Modules\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $tf) {}

    public function status(Request $request): JsonResponse
    {
        $f = UserTwoFactor::where('user_id', $request->user()->id)->first();

        return response()->json(['enabled' => (bool) $f?->confirmed_at, 'pending_setup' => $f && ! $f->confirmed_at,
            'recovery_codes_left' => $f?->confirmed_at ? count($f->recovery_codes ?? []) : 0, 'required' => $this->tf->requiredFor($request->user())]);
    }

    /** Step 1: create (or replace an unconfirmed) secret. Nothing is enforced until confirm(). */
    public function setup(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_if($this->tf->enabled($u), 409, 'احراز هویت دومرحله‌ای از قبل فعال است.');
        $secret = $this->tf->newSecret();
        UserTwoFactor::updateOrCreate(['user_id' => $u->id], ['secret' => $secret, 'confirmed_at' => null, 'recovery_codes' => null, 'last_used_step' => null]);

        return response()->json(['secret' => $secret, 'otpauth_uri' => $this->tf->uri($u, $secret)]);
    }

    /** Step 2: prove the authenticator works; returns the recovery codes ONCE. */
    public function confirm(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $f = UserTwoFactor::where('user_id', $request->user()->id)->whereNull('confirmed_at')->first();
        abort_unless($f, 409, 'ابتدا راه‌اندازی را شروع کنید.');
        if ($this->tf->verify($f, $d['code']) === null) {
            throw ValidationException::withMessages(['code' => ['کد نادرست است.']]);
        }
        [$plain, $hashes] = $this->tf->makeRecoveryCodes();
        $f->forceFill(['confirmed_at' => now(), 'recovery_codes' => $hashes])->save();
        Audit::record('auth.2fa_enabled', $request->user());

        return response()->json(['recovery_codes' => $plain]);
    }

    public function disable(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $f = UserTwoFactor::where('user_id', $u->id)->whereNotNull('confirmed_at')->first();
        abort_unless($f, 404);
        abort_if($this->tf->requiredFor($u), 403, 'سیاست پلتفرم، احراز هویت دومرحله‌ای را برای نقش شما اجباری کرده است.');
        if (! Hash::check($d['password'], $u->password) || ! $this->secondFactorOk($f, $d['code'])) {
            throw ValidationException::withMessages(['code' => ['رمز یا کد نادرست است.']]);
        }
        $f->delete();
        Audit::record('auth.2fa_disabled', $u);

        return response()->json(['message' => 'غیرفعال شد.']);
    }

    /** Second step of login: exchange the short-lived challenge + code (or a recovery code) for an API token. */
    public function verifyLogin(Request $request): JsonResponse
    {
        $d = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'max:20'], 'device_name' => ['nullable', 'string', 'max:60']]);
        try {
            $p = json_decode(Crypt::decryptString($d['challenge']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['code' => ['درخواست ورود نامعتبر یا منقضی است؛ دوباره وارد شوید.']]);
        }
        $key = 'tf-attempts:'.hash('sha256', $d['challenge']);
        if ($p['exp'] < time() || Cache::get($key, 0) >= 5) {
            throw ValidationException::withMessages(['code' => ['درخواست ورود منقضی شد؛ دوباره وارد شوید.']]);
        }
        $user = User::find($p['uid']);
        $f = $user ? UserTwoFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->first() : null;
        if (! $user || $user->status !== 'active' || ! $f) {
            throw ValidationException::withMessages(['code' => ['درخواست ورود نامعتبر است.']]);
        }
        if (! $this->secondFactorOk($f, $d['code'])) {
            Cache::add($key, 0, 600);
            Cache::increment($key);
            throw ValidationException::withMessages(['code' => ['کد نادرست است.']]);
        }
        Cache::forget($key);
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($d['device_name'] ?? 'web', ['*'], now()->addDays(14));
        $request->setUserResolver(fn () => $user);
        Audit::record('auth.2fa_login', $user);

        return response()->json(['token' => $token->plainTextToken, 'user' => app(AuthController::class)->payload($user)]);
    }

    public function challengeFor(User $u): string
    {
        return Crypt::encryptString(json_encode(['uid' => $u->id, 'exp' => time() + 300]));
    }

    private function secondFactorOk(UserTwoFactor $f, string $input): bool
    {
        return $this->tf->verify($f, $input) !== null || $this->tf->useRecoveryCode($f, $input);
    }
}

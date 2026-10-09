<?php

namespace App\Modules\Auth;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\UserTwoFactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) + single-use recovery codes. No third-party dependency. */
class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function uri(User $u, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'School'));

        return 'otpauth://totp/'.$issuer.':'.rawurlencode($u->email ?? "user{$u->id}")."?secret=$secret&issuer=$issuer&algorithm=SHA1&digits=6&period=30";
    }

    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the current step ±1 (clock drift); a step can be used once. Returns the step used or null. */
    public function verify(UserTwoFactor $f, string $input, ?int $now = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input);
        if (! preg_match('/^\d{6}$/', $input)) {
            return null;
        }
        $current = intdiv($now ?? time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $current + $d;
            if (hash_equals($this->code($f->secret, $step), $input) && ($f->last_used_step === null || $step > $f->last_used_step)) {
                $f->forceFill(['last_used_step' => $step])->save();

                return $step;
            }
        }

        return null;
    }

    /** @return array{0: list<string>, 1: list<string>} plain codes (shown once), hashes (stored) */
    public function makeRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < 10; $i++) {
            $plain[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        return [$plain, array_map(fn ($c) => hash('sha256', $c), $plain)];
    }

    public function useRecoveryCode(UserTwoFactor $f, string $input): bool
    {
        $h = hash('sha256', strtolower(trim($input)));
        $codes = $f->recovery_codes ?? [];
        $i = array_search($h, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        $f->forceFill(['recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function enabled(User $u): bool
    {
        return UserTwoFactor::where('user_id', $u->id)->whereNotNull('confirmed_at')->exists();
    }

    /** Platform policy: operators and school admins must have 2FA when `require_2fa_for_admins` is on. */
    public function requiredFor(User $u): bool
    {
        $on = Cache::remember('policy:require_2fa', 60, function () {
            $v = PlatformSetting::where('key', 'require_2fa_for_admins')->first()?->value;

            return (bool) filter_var(is_array($v) ? ($v[0] ?? false) : $v, FILTER_VALIDATE_BOOLEAN);
        });
        if (! $on) {
            return false;
        }

        return $u->platform_role !== null
            || $u->memberships()->where('status', 'active')->whereHas('role', fn ($q) => $q->where('key', 'school_admin'))->exists();
    }

    private function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper($s)) as $c) {
            $p = strpos(self::ALPHABET, $c);
            $bits .= $p === false ? '' : str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            strlen($byte) === 8 && $out .= chr(bindec($byte));
        }

        return $out;
    }
}

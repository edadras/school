<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\UserTwoFactor;
use App\Modules\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    private $school;
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->school = $this->makeSchool('a');
        $this->admin = $this->makeMember($this->school, 'school_admin', 'adm@example.test');
        $this->admin->forceFill(['password' => 'Str0ng-Password1'])->save();
    }

    private function enroll(): array
    {
        $tf = app(TwoFactor::class);
        $setup = $this->as($this->admin, $this->school)->postJson('/api/v1/me/2fa/setup')->assertOk();
        $secret = $setup->json('secret');
        $this->assertStringContainsString('otpauth://totp/', $setup->json('otpauth_uri'));
        $codes = $this->as($this->admin, $this->school)->postJson('/api/v1/me/2fa/confirm', ['code' => $tf->code($secret, intdiv(time(), 30))])->assertOk()->json('recovery_codes');

        return [$secret, $codes];
    }

    public function test_totp_matches_the_rfc6238_test_vector(): void
    {
        // RFC 6238 appendix B, SHA-1, secret "12345678901234567890", T=59 => 94287082 (6 digits: 287082)
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', app(TwoFactor::class)->code($secret, intdiv(59, 30)));
    }

    public function test_login_requires_the_second_factor_and_issues_no_token_before_it(): void
    {
        [$secret, $codes] = $this->enroll();
        $r = $this->postJson('/api/v1/auth/login', ['login' => 'adm@example.test', 'password' => 'Str0ng-Password1'])->assertOk();
        $this->assertTrue($r->json('two_factor_required'));
        $this->assertNull($r->json('token'));

        $ch = $r->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $ch, 'code' => '000000'])->assertStatus(422);
        // a wrong challenge is rejected
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => 'garbage', 'code' => '123456'])->assertStatus(422);

        // fresh step (the enrolment step is already consumed => replay is refused)
        $f = UserTwoFactor::first();
        $f->forceFill(['last_used_step' => intdiv(time(), 30) - 5])->save();
        $ok = $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $ch, 'code' => app(TwoFactor::class)->code($secret, intdiv(time(), 30))])->assertOk();
        $this->assertNotEmpty($ok->json('token'));
        // the same code cannot be replayed with a new challenge
        $ch2 = $this->postJson('/api/v1/auth/login', ['login' => 'adm@example.test', 'password' => 'Str0ng-Password1'])->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $ch2, 'code' => app(TwoFactor::class)->code($secret, intdiv(time(), 30))])->assertStatus(422);
    }

    public function test_recovery_code_works_once(): void
    {
        [, $codes] = $this->enroll();
        $login = fn () => $this->postJson('/api/v1/auth/login', ['login' => 'adm@example.test', 'password' => 'Str0ng-Password1'])->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $login(), 'code' => $codes[0]])->assertOk();
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $login(), 'code' => $codes[0]])->assertStatus(422);
        $this->assertSame(9, UserTwoFactor::first()->fresh()->recovery_codes ? count(UserTwoFactor::first()->recovery_codes) : 0);
    }

    public function test_five_wrong_codes_burn_the_challenge(): void
    {
        $this->enroll();
        $ch = $this->postJson('/api/v1/auth/login', ['login' => 'adm@example.test', 'password' => 'Str0ng-Password1'])->json('challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $ch, 'code' => '111111'])->assertStatus(422);
        }
        $secret = UserTwoFactor::first()->secret;
        UserTwoFactor::first()->forceFill(['last_used_step' => 0])->save();
        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $ch, 'code' => app(TwoFactor::class)->code($secret, intdiv(time(), 30))])->assertStatus(422);
    }

    public function test_platform_policy_blocks_admins_without_2fa_until_they_enrol(): void
    {
        PlatformSetting::create(['key' => 'require_2fa_for_admins', 'value' => [true]]);
        Cache::forget('policy:require_2fa');
        $this->as($this->admin, $this->school)->getJson('/api/v1/academics/grades')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
        $this->as($this->admin, $this->school)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.two_factor_setup_required', true);
        $this->enroll();
        $this->as($this->admin, $this->school)->getJson('/api/v1/academics/grades')->assertOk();
        // and cannot switch it off while required
        $this->as($this->admin, $this->school)->deleteJson('/api/v1/me/2fa', ['password' => 'Str0ng-Password1', 'code' => '123456'])->assertForbidden();
    }

    public function test_disable_needs_password_and_code_when_not_required(): void
    {
        [$secret] = $this->enroll();
        $this->as($this->admin, $this->school)->deleteJson('/api/v1/me/2fa', ['password' => 'wrong', 'code' => '123456'])->assertStatus(422);
        UserTwoFactor::first()->forceFill(['last_used_step' => 0])->save();
        $this->as($this->admin, $this->school)->deleteJson('/api/v1/me/2fa', ['password' => 'Str0ng-Password1', 'code' => app(TwoFactor::class)->code($secret, intdiv(time(), 30))])->assertOk();
        $this->assertSame(0, UserTwoFactor::count());
    }
}

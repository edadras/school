<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesSchools;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use MakesSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_login_me_logout_flow(): void
    {
        $s = $this->makeSchool('a');
        $u = $this->makeMember($s, 'teacher', 't@example.test');

        $token = $this->postJson('/api/v1/auth/login', ['login' => 't@example.test', 'password' => 'Password-12345'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.memberships.0.role', 'teacher');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_wrong_password_and_unknown_user_are_indistinguishable(): void
    {
        $s = $this->makeSchool('a');
        $this->makeMember($s, 'teacher', 't@example.test');

        $a = $this->postJson('/api/v1/auth/login', ['login' => 't@example.test', 'password' => 'bad'])->assertStatus(422)->json();
        $b = $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.test', 'password' => 'bad'])->assertStatus(422)->json();
        $this->assertSame($a, $b);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'x@example.test', 'password' => 'bad'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['login' => 'x@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $s = $this->makeSchool('a');
        $u = $this->makeMember($s, 'teacher', 't@example.test');
        $u->forceFill(['status' => 'disabled'])->save();

        $this->postJson('/api/v1/auth/login', ['login' => 't@example.test', 'password' => 'Password-12345'])->assertStatus(422);
    }

    public function test_unauthenticated_requests_get_401_json(): void
    {
        $this->getJson('/api/v1/academics/grades')->assertUnauthorized();
        $this->getJson('/api/v1/platform/schools')->assertUnauthorized();
    }

    public function test_unauthenticated_call_is_a_json_401_with_hardening_headers_even_without_accept_header(): void
    {
        $r = $this->call('GET', '/api/v1/auth/me');   // no Accept: application/json
        $r->assertStatus(401);
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }
}

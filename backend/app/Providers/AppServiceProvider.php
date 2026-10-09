<?php

namespace App\Providers;

use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): reset per request / per queued job, so tenant state never leaks between them.
        $this->app->scoped(CurrentSchool::class);
    }

    public function boot(): void
    {
        // Brute-force protection: per login identifier AND per IP.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('school-register', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}

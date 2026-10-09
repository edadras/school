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
        $this->app->scoped(\App\Modules\Tenancy\Access::class);
        $this->app->bind(\App\Modules\AI\AiProvider::class, fn () => match (config('ai.provider')) {
            'anthropic' => new \App\Modules\AI\Providers\AnthropicProvider(config('ai.anthropic')),
            'openai' => new \App\Modules\AI\Providers\OpenAiCompatibleProvider(config('ai.openai')),
            default => new \App\Modules\AI\Providers\NullAiProvider,
        });
        $this->app->bind(\App\Modules\Files\Scan\MalwareScanner::class, fn () => config('files.scan.provider') === 'clamav'
            ? new \App\Modules\Files\Scan\ClamAvScanner(config('files.scan')) : new \App\Modules\Files\Scan\NullScanner);
        $this->app->bind(\App\Modules\VirtualClassrooms\Media\MediaProvider::class, fn () => \App\Modules\VirtualClassrooms\Media\MediaManager::make());
    }

    public function boot(): void
    {
        // Brute-force protection: per login identifier AND per IP.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('school-register', fn (Request $r) => Limit::perHour((int) config('limits.register_per_hour'))->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}

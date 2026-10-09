<?php

use App\Modules\Tenancy\Http\RequirePermission;
use App\Modules\Tenancy\Http\RequirePlatformRole;
use App\Modules\Tenancy\Http\ResolveSchool;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'school' => ResolveSchool::class,
            'perm' => RequirePermission::class,
            'platform.role' => RequirePlatformRole::class,
        ]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : null);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: always JSON, never an HTML error page or redirect to a login route.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => true);
    })->create();

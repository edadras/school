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
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'school' => ResolveSchool::class,
            'perm' => RequirePermission::class,
            'platform.role' => RequirePlatformRole::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);   // API: an unauthenticated call is a 401, never a redirect to a (non-existent) login route
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : null);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\RecordMetrics::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: always JSON, never an HTML error page or redirect to a login route.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => true);
        // Error responses are produced outside the middleware pipeline: give them the same hardening headers.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $response->headers->add(['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
            }

            return $response;
        });
        // Surface stable machine-readable codes (e.g. media_unconfigured) set by adapters.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if ($code = $e->getHeaders()['X-Error-Code'] ?? null) {
                return response()->json(['message' => $e->getMessage(), 'code' => $code], $e->getStatusCode());
            }
        });
    })->create();

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cheap request metrics (count, errors, total latency, slow buckets) per hour and per route in the shared cache.
 * Read back by GET /platform/metrics. A failure to record never affects the response.
 */
class RecordMetrics
{
    public const BUCKETS = [50, 100, 250, 500, 1000, 2500];

    public function handle(Request $request, Closure $next)
    {
        $start = hrtime(true);
        $response = $next($request);
        try {
            $ms = (int) round((hrtime(true) - $start) / 1e6);
            $hour = now()->format('YmdH');
            $route = $request->route()?->uri() ?? 'unmatched';
            $fail = $response->getStatusCode() >= 500 ? 1 : 0;
            $client = $response->getStatusCode() >= 400 && ! $fail ? 1 : 0;
            foreach (["h:$hour", "r:$hour:$route"] as $k) {
                $this->bump("m:$k:n", 1);
                $this->bump("m:$k:ms", $ms);
                $this->bump("m:$k:e5", $fail);
                $this->bump("m:$k:e4", $client);
            }
            foreach (self::BUCKETS as $b) {
                $ms > $b && $this->bump("m:h:$hour:gt$b", 1);
            }
            $this->remember($hour, $route);
        } catch (\Throwable) {
            // metrics are best-effort
        }

        return $response;
    }

    private function bump(string $key, int $by): void
    {
        if ($by === 0) {
            return;
        }
        Cache::add($key, 0, 172800);
        Cache::increment($key, $by);
    }

    private function remember(string $hour, string $route): void
    {
        $routes = Cache::get("m:routes:$hour", []);
        if (! in_array($route, $routes, true) && count($routes) < 300) {
            $routes[] = $route;
            Cache::put("m:routes:$hour", $routes, 172800);
        }
    }
}

<?php
/**
 * Laravel middleware for the CodeTracer request panel (RS-M7).
 *
 * The C extension already records the request: it opens a `web-request` span
 * in PHP_RINIT over the worker's continuous recording and settles it in
 * PHP_RSHUTDOWN with method, URL, status, duration, response size and remote
 * address, plus the step range the request occupies.  This middleware adds
 * the two things only the framework knows:
 *
 *   * `http.route`  — the route PATTERN (`/api/users/{user}`), so the panel
 *     can group by endpoint instead of by concrete URL;
 *   * `error.message` — set when the request produced a 5xx.
 *
 * Register it in `bootstrap/app.php` (Laravel 11+):
 *
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->append(\CodeTracer\Laravel\CodeTracerRequestSpan::class);
 *     })
 *
 * or in `app/Http/Kernel.php` (Laravel 10 and earlier), by appending the
 * class to the `$middleware` array.
 *
 * Nothing here depends on a recording being active: `codetracer_span_annotate()`
 * returns false when the extension is not loaded or not recording, so the
 * middleware is safe to leave registered in production.
 */

declare(strict_types=1);

namespace CodeTracer\Laravel;

use Closure;

final class CodeTracerRequestSpan
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next)
    {
        // All per-request state stays in THIS frame.  No static "current
        // span", no request-scoped singleton: an app that dispatches a
        // sub-request through the kernel (Laravel's `handle()` is re-entrant)
        // must not be able to overwrite the outer request's state.
        $this->annotate('framework', 'laravel');

        $response = $next($request);

        $route = $this->routePattern($request);
        if ($route !== null) {
            $this->annotate('http.route', $route);
        }

        $status = method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : 0;
        if ($status >= 500) {
            $this->annotate('error.message', $this->errorMessage($request, $status));
        }

        return $response;
    }

    /**
     * Report the PATTERN, never the concrete path.  Two requests that matched
     * the same route must report the same value, which is the whole point of
     * the panel's route column.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    private function routePattern($request): ?string
    {
        if (!method_exists($request, 'route')) {
            return null;
        }
        $route = $request->route();
        if ($route === null || !is_object($route) || !method_exists($route, 'uri')) {
            return null;
        }
        $uri = (string) $route->uri();
        return $uri === '' ? '/' : '/' . ltrim($uri, '/');
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    private function errorMessage($request, int $status): string
    {
        // Laravel's exception handler has already converted the throwable
        // into a response by the time a terminable middleware sees it, so the
        // exception itself is only reachable through the container's shared
        // instance when the app bound one.
        if (function_exists('app')) {
            try {
                $container = app();
                if (is_object($container) && method_exists($container, 'bound') &&
                    $container->bound('codetracer.exception')) {
                    $e = $container->make('codetracer.exception');
                    if ($e instanceof \Throwable) {
                        return get_class($e) . ': ' . $e->getMessage();
                    }
                }
            } catch (\Throwable $ignored) {
                // Never let observation break the request.
            }
        }
        return 'HTTP ' . $status . ' from ' . $request->getPathInfo();
    }

    private function annotate(string $key, string $value): void
    {
        if (function_exists('codetracer_span_annotate')) {
            codetracer_span_annotate($key, $value);
        }
    }
}

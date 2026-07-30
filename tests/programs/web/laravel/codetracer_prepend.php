<?php
/**
 * Zero-touch Laravel integration for the CodeTracer request panel (RS-M7).
 *
 * The other half of `CodeTracerRequestSpan.php`: same annotations, but wired
 * through `auto_prepend_file` so an existing Laravel application needs no
 * source change at all.
 *
 *     php -d extension=/path/to/codetracer.so \
 *         -d auto_prepend_file=/path/to/codetracer_prepend.php \
 *         -S 127.0.0.1:8080 -t public public/index.php
 *
 *     ; or in a php-fpm pool:
 *     php_admin_value[auto_prepend_file] = /path/to/codetracer_prepend.php
 *
 * Prefer the middleware when you can edit the app: it runs inside the
 * framework's own pipeline and sees the resolved route directly, whereas this
 * file has to reach into the container at shutdown.  Prefer this one when you
 * cannot, or when you want the same file to work across Laravel versions
 * whose middleware registration differs.
 */

declare(strict_types=1);

if (getenv('CODETRACER_ENABLED') !== '1' ||
    !function_exists('codetracer_span_annotate')) {
    return;
}

codetracer_span_annotate('framework', 'laravel');

register_shutdown_function(static function (): void {
    // By shutdown the router has resolved, so `Route::current()` is the route
    // this request actually matched.  The extension settles the span in its
    // own PHP_RSHUTDOWN, which runs after userland shutdown functions, so
    // this annotation still lands on the record.
    try {
        if (class_exists(\Illuminate\Support\Facades\Route::class, false)) {
            $current = \Illuminate\Support\Facades\Route::current();
            if ($current !== null && method_exists($current, 'uri')) {
                $uri = (string) $current->uri();
                codetracer_span_annotate('http.route',
                    $uri === '' ? '/' : '/' . ltrim($uri, '/'));
            }
        }
    } catch (\Throwable $ignored) {
        // Observation must never break the request.
    }

    $error = error_get_last();
    if ($error !== null && in_array($error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        codetracer_span_annotate('error.message',
            $error['message'] . ' at ' . $error['file'] . ':' . $error['line']);
    }
});

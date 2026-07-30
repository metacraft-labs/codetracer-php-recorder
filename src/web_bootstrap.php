<?php
/**
 * CodeTracer PHP web bootstrap (RS-M7).
 *
 * Include via `auto_prepend_file` when you want the recorder to label the
 * requests it is already recording:
 *
 *     php -d auto_prepend_file=/path/to/web_bootstrap.php -S localhost:8080
 *     ; or in a php-fpm pool:
 *     php_admin_value[auto_prepend_file] = /path/to/web_bootstrap.php
 *
 * WHAT CHANGED IN RS-M7
 *
 * This file used to create a per-request trace directory, point the C
 * extension at it through CODETRACER_TRACE_DIR, and append a line to a
 * `session_manifest.jsonl` sidecar next to it.  All three are gone:
 *
 *   * the recorder now keeps ONE continuous recording per worker and marks
 *     each request as a span over that timeline, so there is no per-request
 *     directory to create;
 *   * request metadata lives in the container's own `spans.dat` stream, so
 *     there is no sidecar to write, find, tail or keep in sync — see
 *     `codetracer-specs/Trace-Files/CTFS-Request-Span-Streams.md`.
 *
 * What is left is the part the C extension genuinely cannot do on its own:
 * naming the framework, and letting the application contribute a route
 * pattern and an error message.  Everything else — method, URL, status,
 * duration, response size, remote address, and the step range the request
 * occupies — the extension observes directly.
 *
 * Including this file is entirely optional.  Without it the recorder still
 * emits a complete `web-request` span per request.
 */

if (getenv('CODETRACER_ENABLED') === '1' &&
    function_exists('codetracer_span_annotate')) {

    $__ct_framework = getenv('CODETRACER_FRAMEWORK');
    if (is_string($__ct_framework) && $__ct_framework !== '') {
        codetracer_span_annotate('framework', $__ct_framework);
    }

    // Annotate at shutdown so a router that resolves late (every framework
    // does) has had its chance to publish a route.  The extension settles the
    // span in its own RSHUTDOWN, which runs after userland shutdown
    // functions, so anything added here still lands on the record.
    register_shutdown_function(static function (): void {
        $route = getenv('CODETRACER_ROUTE');
        if (is_string($route) && $route !== '') {
            codetracer_span_annotate('http.route', $route);
        }
        $error = error_get_last();
        if ($error !== null && in_array($error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            codetracer_span_annotate('error.message',
                $error['message'] . ' at ' . $error['file'] . ':' . $error['line']);
        }
    });
}

<?php
/**
 * CodeTracer auto_prepend_file bootstrap.
 *
 * Usage:
 *   php -d auto_prepend_file=path/to/auto_prepend.php your_script.php
 *
 * Or in php.ini / php-fpm pool config:
 *   auto_prepend_file = /path/to/auto_prepend.php
 */

require_once __DIR__ . '/span.php';

// Begin span tracking
$__codetracer_span = CodeTracerSpan::begin();

// Register shutdown function to end the span
register_shutdown_function(function() use ($__codetracer_span) {
    $statusCode = http_response_code() ?: 200;
    $__codetracer_span->end($statusCode);
});

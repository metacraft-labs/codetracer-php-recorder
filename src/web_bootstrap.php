<?php
/**
 * CodeTracer PHP Web Bootstrap.
 *
 * Include via auto_prepend_file to enable per-request recording.
 * Each request gets its own trace directory.
 *
 * Usage:
 *   php -d auto_prepend_file=/path/to/web_bootstrap.php -S localhost:8080
 *   Or in php-fpm pool: php_admin_value[auto_prepend_file] = /path/to/web_bootstrap.php
 */

require_once __DIR__ . '/span.php';
require_once __DIR__ . '/ct_runtime.php';

$__ct_enabled = getenv('CODETRACER_ENABLED') === '1';

if ($__ct_enabled) {
    $__ct_base_dir = getenv('CODETRACER_OUTPUT_DIR') ?: '/tmp/codetracer_traces';
    $__ct_method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $__ct_uri = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? 'unknown';

    // Create per-request trace directory
    $__ct_safe_uri = preg_replace('/[^a-zA-Z0-9_-]/', '_', $__ct_uri);
    $__ct_trace_dir = sprintf('%s/%s_%s_%s_%d',
        $__ct_base_dir,
        date('Ymd_His'),
        strtolower($__ct_method),
        substr($__ct_safe_uri, 0, 50),
        getmypid()
    );

    if (!is_dir($__ct_base_dir)) @mkdir($__ct_base_dir, 0755, true);
    @mkdir($__ct_trace_dir, 0755, true);

    // Set trace dir for the runtime
    putenv("CODETRACER_TRACE_DIR=$__ct_trace_dir");

    // Begin span tracking
    $__ct_span = CodeTracerSpan::begin();

    // Register shutdown to end span and flush trace
    register_shutdown_function(function() use ($__ct_span, $__ct_trace_dir) {
        $statusCode = http_response_code() ?: 200;
        $__ct_span->end($statusCode);

        // Write session manifest entry
        $manifestPath = dirname($__ct_trace_dir) . '/session_manifest.jsonl';
        $entry = json_encode([
            'trace_dir' => $__ct_trace_dir,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'status_code' => $statusCode,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($manifestPath, $entry, FILE_APPEND | LOCK_EX);
    });
}

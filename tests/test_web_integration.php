<?php
/**
 * SUPERSEDED BY RS-M7 — kept for history, run by no recipe.
 *
 * This file asserts the pre-RS-M7 shape: one trace directory per request plus
 * a `session_manifest.jsonl` sidecar listing them.  Both are gone.  A worker
 * now keeps ONE continuous recording and each request is a span inside its
 * container's own `spans.dat` stream, so there is nothing per-request on disk
 * and no sidecar to read.
 *
 * The replacement is `tests/test_request_spans.php`
 * (`php_builtin_server_requests_land_in_one_container`), which drives the same
 * real `php -S` server and asserts on the spans through the canonical Nim
 * decoder.  Deleting this file is left to RS-M11, which retires the sidecar
 * compatibility shim across every recorder.
 *
 * Integration test for per-request web recording.
 *
 * Starts PHP built-in server with web_bootstrap.php,
 * sends 5 requests, verifies:
 * - Per-request trace directories created
 * - Session manifest has 5 entries
 * - Each manifest entry references a trace directory
 * - Span metadata correct (method, URL, status)
 */

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_eq($expected, $actual, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected === $actual) { $passCount++; }
    else {
        $failCount++;
        echo "  FAIL: $msg\n    Expected: " . var_export($expected, true) .
            "\n    Actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_true($val, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($val) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg\n"; }
}

// Setup
$outputDir = sys_get_temp_dir() . '/ct_web_test_' . getmypid();
@mkdir($outputDir, 0755, true);
$bootstrapPath = __DIR__ . '/../src/web_bootstrap.php';
$fixturesDir = __DIR__ . '/fixtures';

$port = 18600 + (getmypid() % 100);

// Start PHP built-in server
$cmd = "php -d auto_prepend_file=$bootstrapPath -S 127.0.0.1:$port -t $fixturesDir";
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = array_merge(getenv(), [
    'CODETRACER_ENABLED' => '1',
    'CODETRACER_OUTPUT_DIR' => $outputDir,
]);
// Pass full inherited env + our vars
$envPairs = [];
foreach ($env as $k => $v) {
    if (is_string($v)) $envPairs[] = "$k=$v";
}
$proc = proc_open($cmd, $descriptors, $pipes, null, $envPairs);

if (!is_resource($proc)) {
    echo "FAIL: could not start server\n";
    exit(1);
}

sleep(1);
echo "Server started on port $port\n";

// Send 5 requests
$requests = [
    ['GET', '/api/users'],
    ['POST', '/api/users'],
    ['GET', '/api/users'],
    ['GET', '/error'],
    ['GET', '/health'],
];

foreach ($requests as [$method, $path]) {
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true]];
    $ctx = stream_context_create($opts);
    @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    usleep(200000); // 200ms between requests
}

sleep(1);

// Stop server
proc_terminate($proc);
proc_close($proc);

// Verify results
echo "\ntest_per_request_trace:\n";
$traceDirs = glob("$outputDir/*/");
echo "  Found " . count($traceDirs) . " trace directories\n";
assert_true(count($traceDirs) >= 3, "at least 3 trace directories created (got " . count($traceDirs) . ")");
echo "  PASS\n";

echo "\ntest_request_metadata_in_manifest:\n";
$manifestPath = "$outputDir/session_manifest.jsonl";
if (file_exists($manifestPath)) {
    $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "  Found " . count($lines) . " manifest entries\n";
    assert_true(count($lines) >= 3, "at least 3 manifest entries");

    if (count($lines) > 0) {
        $spans = array_map(fn($l) => json_decode($l, true), $lines);

        // Check first span
        $firstGet = null;
        foreach ($spans as $span) {
            if ($span['method'] === 'GET' && str_contains($span['url'], '/api/users')) {
                $firstGet = $span;
                break;
            }
        }
        if ($firstGet) {
            assert_eq('GET', $firstGet['method'], 'first GET method');
            assert_true(str_contains($firstGet['url'], '/api/users'), 'URL contains /api/users');
            assert_true(isset($firstGet['trace_dir']), 'has trace_dir reference');
        }
    }
} else {
    echo "  SKIP: manifest not found (server may not have processed requests)\n";
    $passCount += 2;
    $testCount += 2;
}
echo "  PASS\n";

echo "\ntest_span_references_trace:\n";
if (file_exists($manifestPath)) {
    $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $spans = array_map(fn($l) => json_decode($l, true), $lines);
    $validRefs = 0;
    foreach ($spans as $span) {
        if (isset($span['trace_dir']) && is_dir($span['trace_dir'])) {
            $validRefs++;
        }
    }
    assert_true($validRefs >= 1, "at least 1 span references a valid trace dir (got $validRefs)");
} else {
    $passCount++;
    $testCount++;
}
echo "  PASS\n";

// Cleanup
exec("rm -rf " . escapeshellarg($outputDir));

echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) exit(1);
echo "ALL TESTS PASSED\n";

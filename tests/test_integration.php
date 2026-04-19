<?php
/**
 * Integration test: starts PHP built-in server with span tracking,
 * sends real HTTP requests, verifies spans in manifest.
 */

$manifestPath = '/tmp/codetracer_php_integration_' . getmypid() . '.jsonl';
@unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_eq($expected, $actual, string $msg): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected === $actual) {
        $passCount++;
    } else {
        $failCount++;
        echo "  FAIL: $msg\n    Expected: " . var_export($expected, true) .
            "\n    Actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_gte($value, $min, string $msg): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($value >= $min) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg (got $value, expected >= $min)\n"; }
}

// Find the auto_prepend and fixtures
$srcDir = __DIR__ . '/../src';
$fixturesDir = __DIR__ . '/fixtures';
$autoPrepend = $srcDir . '/auto_prepend.php';

// Pick a random port
$port = 18700 + (getmypid() % 100);

// Start PHP built-in server
$cmd = "php -d auto_prepend_file=$autoPrepend -S 127.0.0.1:$port -t $fixturesDir";
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['CODETRACER_SPAN_MANIFEST'] = $manifestPath;
// Ensure PATH is available for the child process
if (!isset($env['PATH'])) {
    $env['PATH'] = getenv('PATH');
}
$proc = proc_open($cmd, $descriptors, $pipes, null, $env);

if (!is_resource($proc)) {
    echo "FAIL: could not start PHP server\n";
    exit(1);
}

// Wait for server to start
sleep(1);

echo "PHP server started on port $port\n";

// Send 5 requests
$requests = [
    ['GET',  "/api/users"],
    ['POST', "/api/users"],
    ['GET',  "/api/users"],
    ['GET',  "/error"],
    ['GET',  "/health"],
];

foreach ($requests as [$method, $path]) {
    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
    ]];
    $ctx = stream_context_create($opts);
    $response = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    echo "  $method $path -> " . ($response !== false ? strlen($response) . " bytes" : "error") . "\n";
    usleep(100000); // 100ms between requests
}

// Wait for spans to be written
sleep(1);

// Stop server
proc_terminate($proc);
proc_close($proc);

// Read manifest
echo "\nVerifying spans...\n";

if (!file_exists($manifestPath)) {
    echo "FAIL: manifest file not created at $manifestPath\n";
    exit(1);
}

$lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
assert_eq(5, count($lines), "5 spans in manifest");

$spans = array_map(fn($l) => json_decode($l, true), $lines);

// Verify each span
assert_eq('GET', $spans[0]['metadata']['http.method'], 'span 0 method');
assert_eq('/api/users', $spans[0]['metadata']['http.url'], 'span 0 url');
assert_eq('200', $spans[0]['metadata']['http.status_code'], 'span 0 status');

assert_eq('POST', $spans[1]['metadata']['http.method'], 'span 1 method');
assert_eq('201', $spans[1]['metadata']['http.status_code'], 'span 1 status');

assert_eq('GET', $spans[2]['metadata']['http.method'], 'span 2 method');

assert_eq('GET', $spans[3]['metadata']['http.method'], 'span 3 method');
assert_eq('500', $spans[3]['metadata']['http.status_code'], 'span 3 status');
assert_eq('error', $spans[3]['status'], 'span 3 status field');

assert_eq('GET', $spans[4]['metadata']['http.method'], 'span 4 method');
assert_eq('/health', $spans[4]['metadata']['http.url'], 'span 4 url');

// Verify all have duration >= 0
foreach ($spans as $i => $span) {
    assert_gte((int)$span['metadata']['http.duration_ms'], 0, "span $i duration >= 0");
    assert_eq('web-request', $span['span_type'], "span $i span_type");
}

// Cleanup
@unlink($manifestPath);

echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) { exit(1); }
echo "ALL INTEGRATION TESTS PASSED\n";

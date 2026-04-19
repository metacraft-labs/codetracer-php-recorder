<?php
/**
 * Unit tests for CodeTracer PHP span tracking.
 */

require_once __DIR__ . '/../src/span.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_equals($expected, $actual, string $message): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected === $actual) {
        $passCount++;
    } else {
        $failCount++;
        echo "  FAIL: $message\n";
        echo "    Expected: " . var_export($expected, true) . "\n";
        echo "    Actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_true($value, string $message): void {
    assert_equals(true, $value, $message);
}

function assert_contains(string $needle, string $haystack, string $message): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if (str_contains($haystack, $needle)) {
        $passCount++;
    } else {
        $failCount++;
        echo "  FAIL: $message (needle '$needle' not found)\n";
    }
}

// --- Tests ---

echo "Running PHP span tests...\n";

// Test 1: test_php_recorder_produces_span
echo "\ntest_php_recorder_produces_span:\n";
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_span_');
unlink($manifestPath); // Start fresh
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/users';
CodeTracerSpan::reset();

$span = CodeTracerSpan::begin();
$span->end(200);

assert_true(file_exists($manifestPath), "manifest file created");
$lines = file($manifestPath, FILE_IGNORE_NEW_LINES);
assert_equals(1, count($lines), "1 span in manifest");
$data = json_decode($lines[0], true);
assert_equals('GET', $data['metadata']['http.method'], "method is GET");
assert_equals('/api/users', $data['metadata']['http.url'], "url is /api/users");
assert_equals('200', $data['metadata']['http.status_code'], "status is 200");
assert_equals('ok', $data['status'], "status is ok");
assert_contains('span_php_', $data['id'], "span ID has prefix");
unlink($manifestPath);
echo "  PASS: test_php_recorder_produces_span\n";

// Test 2: test_php_span_metadata
echo "\ntest_php_span_metadata:\n";
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_span_');
unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/users';
CodeTracerSpan::reset();

$span = CodeTracerSpan::begin();
$span->end(201);

$lines = file($manifestPath, FILE_IGNORE_NEW_LINES);
$data = json_decode($lines[0], true);
assert_equals('POST', $data['metadata']['http.method'], "method is POST");
assert_equals('201', $data['metadata']['http.status_code'], "status is 201");
assert_equals('web-request', $data['span_type'], "span_type is web-request");
assert_equals('POST /api/users', $data['label'], "label matches");
unlink($manifestPath);
echo "  PASS: test_php_span_metadata\n";

// Test 3: test_php_fpm_multiple_requests
echo "\ntest_php_fpm_multiple_requests:\n";
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_span_');
unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");

$methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
foreach ($methods as $i => $method) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = "/test/$i";
    CodeTracerSpan::reset();
    $span = CodeTracerSpan::begin();
    $span->end(200);
}

$lines = file($manifestPath, FILE_IGNORE_NEW_LINES);
assert_equals(5, count($lines), "5 spans for 5 requests");

foreach ($lines as $i => $line) {
    $data = json_decode($line, true);
    assert_equals($methods[$i], $data['metadata']['http.method'], "method $i matches");
    assert_equals("/test/$i", $data['metadata']['http.url'], "url $i matches");
}
unlink($manifestPath);
echo "  PASS: test_php_fpm_multiple_requests\n";

// Test 4: test_php_error_span
echo "\ntest_php_error_span:\n";
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_span_');
unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/error';
CodeTracerSpan::reset();

$span = CodeTracerSpan::begin();
$span->end(500);

$lines = file($manifestPath, FILE_IGNORE_NEW_LINES);
$data = json_decode($lines[0], true);
assert_equals('error', $data['status'], "error status for 500");
assert_equals('500', $data['metadata']['http.status_code'], "status code 500");
unlink($manifestPath);
echo "  PASS: test_php_error_span\n";

// Test 5: test_php_duration_recorded
echo "\ntest_php_duration_recorded:\n";
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_span_');
unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/slow';
CodeTracerSpan::reset();

$span = CodeTracerSpan::begin();
usleep(10000); // 10ms
$span->end(200);

$lines = file($manifestPath, FILE_IGNORE_NEW_LINES);
$data = json_decode($lines[0], true);
$duration = (int)$data['metadata']['http.duration_ms'];
assert_true($duration >= 5, "duration >= 5ms (got {$duration}ms)");
unlink($manifestPath);
echo "  PASS: test_php_duration_recorded\n";

// Summary
echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) {
    exit(1);
}
echo "ALL TESTS PASSED\n";

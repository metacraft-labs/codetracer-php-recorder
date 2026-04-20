<?php
/**
 * E2E test verifying PHP recorder spans are compatible with
 * the CodeTracer HTTP Request Panel.
 *
 * Tests that the JSONL manifest format matches what the panel expects:
 * - Each entry has id, label, span_type, metadata, status
 * - metadata has http.method, http.url, http.status_code, http.duration_ms
 * - All values are strings (the panel parses them)
 */

require_once __DIR__ . '/../src/span.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_eq($expected, $actual, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($expected === $actual) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg (expected=$expected, actual=$actual)\n"; }
}

function assert_true($val, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($val) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg\n"; }
}

// Create spans simulating 5 PHP requests
$manifestPath = tempnam(sys_get_temp_dir(), 'ct_panel_');
unlink($manifestPath);
putenv("CODETRACER_SPAN_MANIFEST=$manifestPath");

$requests = [
    ['GET', '/api/users', 200],
    ['POST', '/api/users', 201],
    ['GET', '/api/users/42', 200],
    ['DELETE', '/api/users/42', 204],
    ['GET', '/error', 500],
];

foreach ($requests as [$method, $path, $status]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    CodeTracerSpan::reset();
    $span = CodeTracerSpan::begin();
    usleep(1000); // Small delay for duration
    $span->end($status);
}

// Read and verify the manifest
echo "test_e2e_request_panel_format:\n";
assert_true(file_exists($manifestPath), "manifest file exists");
$lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
assert_eq(5, count($lines), "5 spans in manifest");

echo "\ntest_panel_compatible_fields:\n";
foreach ($lines as $i => $line) {
    $span = json_decode($line, true);

    // Required top-level fields
    assert_true(isset($span['id']), "span $i has id");
    assert_true(isset($span['label']), "span $i has label");
    assert_true(isset($span['span_type']), "span $i has span_type");
    assert_true(isset($span['metadata']), "span $i has metadata");
    assert_true(isset($span['status']), "span $i has status");

    // Required metadata fields (as strings -- the panel parses them)
    $meta = $span['metadata'];
    assert_true(isset($meta['http.method']), "span $i has http.method");
    assert_true(isset($meta['http.url']), "span $i has http.url");
    assert_true(isset($meta['http.status_code']), "span $i has http.status_code");
    assert_true(isset($meta['http.duration_ms']), "span $i has http.duration_ms");

    // Values match what was sent
    assert_eq($requests[$i][0], $meta['http.method'], "span $i method");
    assert_eq($requests[$i][1], $meta['http.url'], "span $i url");
    assert_eq((string)$requests[$i][2], $meta['http.status_code'], "span $i status");

    // Duration is a positive number string
    assert_true((int)$meta['http.duration_ms'] >= 0, "span $i duration >= 0");

    // Status field matches
    $expectedStatus = $requests[$i][2] >= 400 ? 'error' : 'ok';
    assert_eq($expectedStatus, $span['status'], "span $i status field");
}

echo "\ntest_span_type_is_web_request:\n";
foreach ($lines as $i => $line) {
    $span = json_decode($line, true);
    assert_eq('web-request', $span['span_type'], "span $i type");
}

// Cleanup
unlink($manifestPath);

echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) exit(1);
echo "ALL E2E TESTS PASSED\n";

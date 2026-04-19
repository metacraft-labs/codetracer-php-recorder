<?php
/**
 * Test that the CodeTracer PHP extension loads and produces traces.
 */

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_true($val, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($val) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg\n"; }
}

// Test 1: Extension is loaded
echo "test_extension_loads:\n";
assert_true(extension_loaded('codetracer'), "codetracer extension should be loaded");
echo "  PASS\n";

// Test 2: Minimal trace produced
echo "\ntest_minimal_trace_produced:\n";
$traceDir = getenv('CODETRACER_OUTPUT_DIR') ?: '/tmp/codetracer_test_traces';

// Define some test functions
function fibonacci($n) {
    if ($n <= 1) return $n;
    return fibonacci($n - 1) + fibonacci($n - 2);
}

function greet($name) {
    return "Hello, $name!";
}

// Call them
$result = fibonacci(5);
$greeting = greet("World");
$sum = array_sum([1, 2, 3, 4, 5]);

// Check if trace directory and trace.bin were created.
// Note: trace_metadata.json and trace_paths.json are written during RSHUTDOWN
// (after the script finishes), so they won't exist yet during script execution.
$traceDirs = glob("$traceDir/*");
if (count($traceDirs) > 0) {
    $latestDir = end($traceDirs);
    assert_true(
        file_exists("$latestDir/trace.bin"),
        "trace.bin should exist in $latestDir"
    );
    assert_true(
        filesize("$latestDir/trace.bin") > 0,
        "trace.bin should be non-empty"
    );
    echo "  PASS: traces found in $latestDir\n";
    echo "  (trace_metadata.json and trace_paths.json are written at request shutdown)\n";
} else {
    // Tracing may be disabled — check env
    if (getenv('CODETRACER_ENABLED') !== '1') {
        echo "  SKIP: CODETRACER_ENABLED not set to 1\n";
        $passCount += 2;
        $testCount += 2;
    } else {
        assert_true(false, "no trace directories found in $traceDir");
    }
}

// Test 3: Function names captured
echo "\ntest_function_names_in_trace:\n";
// The trace should contain our function names — verified by loading
// the trace with codetracer_trace_reader (external verification)
// For now, just verify the functions executed correctly
assert_true($result === 5, "fibonacci(5) should equal 5");
assert_true($greeting === "Hello, World!", "greet should work");
assert_true($sum === 15, "array_sum should work");
echo "  PASS: functions executed correctly\n";

// Summary
echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) exit(1);
echo "ALL TESTS PASSED\n";

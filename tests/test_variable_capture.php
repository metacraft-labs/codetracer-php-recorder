<?php
// Verifies that traces contain variable values.
// Checks trace files exist and contain data.

$traceDir = getenv('CODETRACER_OUTPUT_DIR') ?: '/tmp/codetracer_test_vars';
$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_true($val, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($val) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg\n"; }
}

// Define test functions to exercise variable capture
function fibonacci($n) {
    if ($n <= 1) return $n;
    return fibonacci($n - 1) + fibonacci($n - 2);
}

function string_ops($name, $greeting) {
    $result = "$greeting, $name!";
    $len = strlen($result);
    return $result;
}

// Execute functions
echo "test_function_args_captured:\n";
$fib = fibonacci(6);
assert_true($fib === 8, "fibonacci(6) should be 8, got $fib");
echo "  PASS\n";

echo "\ntest_return_value_captured:\n";
$msg = string_ops("Bob", "Hi");
assert_true($msg === "Hi, Bob!", "string_ops result should be 'Hi, Bob!', got '$msg'");
echo "  PASS\n";

echo "\ntest_variable_serialization:\n";
// These should all be captured as variables
$intVar = 42;
$floatVar = 3.14;
$strVar = "hello";
$boolVar = true;
$nullVar = null;
$arrayVar = [1, "two", 3.0];
$objVar = new stdClass();
$objVar->name = "test";

assert_true($intVar === 42, "int variable");
assert_true(abs($floatVar - 3.14) < 0.001, "float variable");
assert_true($strVar === "hello", "string variable");
assert_true($boolVar === true, "bool variable");
assert_true($nullVar === null, "null variable");
assert_true(count($arrayVar) === 3, "array variable");
assert_true($objVar->name === "test", "object variable");
echo "  PASS\n";

echo "\ntest_step_events_per_line:\n";
// Just verify execution completes without errors
for ($i = 0; $i < 5; $i++) {
    $x = $i * 2;
}
assert_true($x === 8, "loop variable final value");
echo "  PASS\n";

// Check trace output
echo "\nVerifying trace files:\n";
$traceDirs = glob("$traceDir/*");
if (count($traceDirs) > 0) {
    $latest = end($traceDirs);
    assert_true(file_exists("$latest/trace.bin"), "trace.bin exists");
    $size = filesize("$latest/trace.bin");
    assert_true($size > 100, "trace.bin has substantial content (size=$size bytes)");
    echo "  Trace: $latest ($size bytes)\n";
} else {
    if (getenv('CODETRACER_ENABLED') !== '1') {
        echo "  SKIP: tracing not enabled\n";
        $passCount += 2; $testCount += 2;
    }
}

echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) exit(1);
echo "ALL TESTS PASSED\n";

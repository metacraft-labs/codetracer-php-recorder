<?php
require_once __DIR__ . '/../src/instrumenter.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assert_true($val, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($val) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg\n"; }
}

function assert_contains($needle, $haystack, $msg) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if (str_contains($haystack, $needle)) { $passCount++; }
    else { $failCount++; echo "  FAIL: $msg (needle '$needle' not found)\n"; }
}

$instrumenter = new CodeTracerInstrumenter();

// Test 1: Instrumented code is valid PHP
echo "test_instrumenter_produces_valid_php:\n";
$source = '<?php
function add($a, $b) {
    return $a + $b;
}
$result = add(3, 7);
echo $result;
';
$instrumented = $instrumenter->instrumentSource($source);
assert_true(strlen($instrumented) > strlen($source), "instrumented should be longer");
// Verify it's valid PHP by checking it doesn't cause a parse error
$tmpFile = tempnam(sys_get_temp_dir(), 'ct_inst_');
file_put_contents($tmpFile, $instrumented);
$output = [];
$exitCode = 0;
exec("php -l $tmpFile 2>&1", $output, $exitCode);
assert_true($exitCode === 0, "instrumented code should be valid PHP (got: " . implode(' ', $output) . ")");
unlink($tmpFile);
echo "  PASS\n";

// Test 2: Instrumented code contains tracing calls
echo "\ntest_instrumented_contains_tracing_calls:\n";
assert_contains('__ct_step(', $instrumented, "should contain step calls");
assert_contains('__ct_call(', $instrumented, "should contain call instrumentation");
assert_contains('__ct_return()', $instrumented, "should contain return instrumentation");
echo "  PASS\n";

// Test 3: Instrumented code executes correctly
echo "\ntest_instrumented_executes_correctly:\n";
// Include the runtime and run the instrumented code
$runtimePath = __DIR__ . '/../src/ct_runtime.php';
$testCode = "<?php\nrequire_once '$runtimePath';\n" . substr($instrumented, 5); // strip <?php
$tmpFile = tempnam(sys_get_temp_dir(), 'ct_run_');
file_put_contents($tmpFile, $testCode);
$output = [];
exec("php $tmpFile 2>&1", $output, $exitCode);
assert_true($exitCode === 0, "instrumented code should execute without errors");
assert_true(implode('', $output) === '10', "add(3,7) should output 10");
unlink($tmpFile);
echo "  PASS\n";

// Test 4: Directory instrumentation
echo "\ntest_directory_instrumentation:\n";
$tmpInputDir = sys_get_temp_dir() . '/ct_inst_input_' . getmypid();
$tmpOutputDir = sys_get_temp_dir() . '/ct_inst_output_' . getmypid();
@mkdir($tmpInputDir, 0755, true);

file_put_contents("$tmpInputDir/main.php", '<?php echo "hello";');
file_put_contents("$tmpInputDir/helper.php", '<?php function helper() { return 42; }');

$count = $instrumenter->instrumentDirectory($tmpInputDir, $tmpOutputDir);
assert_true($count === 2, "should instrument 2 files, got $count");
assert_true(file_exists("$tmpOutputDir/main.php"), "main.php should exist in output");
assert_true(file_exists("$tmpOutputDir/helper.php"), "helper.php should exist in output");

// Verify instrumented files are valid
exec("php -l $tmpOutputDir/main.php 2>&1", $output, $exitCode);
assert_true($exitCode === 0, "instrumented main.php should be valid");
exec("php -l $tmpOutputDir/helper.php 2>&1", $output, $exitCode);
assert_true($exitCode === 0, "instrumented helper.php should be valid");

// Cleanup
array_map('unlink', glob("$tmpInputDir/*"));
array_map('unlink', glob("$tmpOutputDir/*"));
@rmdir($tmpInputDir);
@rmdir($tmpOutputDir);
echo "  PASS\n";

echo "\n" . str_repeat("=", 40) . "\n";
echo "Tests: $testCount, Passed: $passCount, Failed: $failCount\n";
if ($failCount > 0) exit(1);
echo "ALL TESTS PASSED\n";

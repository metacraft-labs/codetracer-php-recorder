<?php
/**
 * Canonical cross-recorder flow test.
 *
 * Defined by metacraft-specs/policies/recorder-test-requirements.md §4:
 *
 *     a = 10
 *     b = 32
 *     sum_val = a + b           # 42
 *     doubled = sum_val * 2     # 84
 *     final_result = doubled + a # 94
 *
 * If your recorder runs flow_test.php through the standard entry
 * point and these five let-bindings don't surface in the trace, that
 * is the bug to chase — the same canonical fixture exists for cairo,
 * leo, aiken/cardano, ruby, python, etc.
 *
 * Wrapped in a function so the recorder's per-function call tracking
 * has something to attach to (otherwise zend_execute_ex sees only the
 * top-level "main" frame).
 */

function compute(): int {
    $a = 10;
    $b = 32;
    $sum_val = $a + $b;        // 42
    $doubled = $sum_val * 2;   // 84
    $final_result = $doubled + $a; // 94
    return $final_result;
}

$result = compute();
// Print so the test harness can substring-check stdout in addition to
// the trace bundle (defence in depth — the trace bundle is the
// authoritative assertion target per §1).
echo "flow_test result: $result\n";

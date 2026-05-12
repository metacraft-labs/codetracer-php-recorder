<?php
/**
 * Nested + recursive call coverage.
 *
 * - 4-deep call chain (compute -> outer -> middle -> inner) so the
 *   recorder's call-entry / call-exit ordering is exercised at depth
 *   greater than the universal-checklist minimum (>=3).
 * - factorial(5) provides a recursive call so the recorder must
 *   distinguish each frame's function-entry step independently.
 *
 * Per metacraft-specs/policies/recorder-test-requirements.md §2 the
 * universal checklist requires "nested calls (>=3 deep), recursive
 * calls".  Both are present here.
 */

function inner(int $a, int $b): int {
    $c = $a + $b;
    return $c;
}

function middle(int $x): int {
    $y = inner(1, 2) + $x; // inner returns 3; y = 3 + x
    return $y;
}

function outer(int $p): int {
    $q = middle($p) + 100;
    return $q;
}

function compute(): int {
    $start = 10;
    $result = outer($start); // outer(10) -> middle(10) -> 3+10=13 -> 13+100 = 113
    return $result;
}

function factorial(int $n): int {
    if ($n <= 1) return 1;
    return $n * factorial($n - 1);
}

$nested = compute();
$fact = factorial(5); // 120
echo "nested=$nested fact=$fact\n";

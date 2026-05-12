<?php
/**
 * Generator coverage.
 *
 * Per recorder-test-requirements.md §2 PHP-specific row, generators
 * are required coverage.  PHP generators (`function () { yield ...; }`)
 * are implemented as zend_generator objects; the recorder sees each
 * resumption as a separate zend_execute_ex call which makes the trace
 * shape interesting (not a single linear frame).
 *
 * Covers:
 *   - bare `yield $value`
 *   - `yield $key => $value`
 *   - `foreach` over a generator (the canonical consumer)
 *   - `return` value from a generator (PHP 7+) accessed via
 *     `Generator::getReturn()`
 */

function int_range(int $lo, int $hi): \Generator {
    for ($i = $lo; $i <= $hi; $i++) {
        yield $i;
    }
    return ($hi - $lo + 1); // count of yielded values
}

function keyed(): \Generator {
    yield "a" => 1;
    yield "b" => 2;
    yield "c" => 3;
}

function consume_range(int $lo, int $hi): array {
    $values = [];
    $gen = int_range($lo, $hi);
    foreach ($gen as $v) {
        $values[] = $v;
    }
    $values[] = "ret=" . $gen->getReturn();
    return $values;
}

function consume_keyed(): array {
    $pairs = [];
    foreach (keyed() as $k => $v) {
        $pairs[] = "$k=$v";
    }
    return $pairs;
}

$range = consume_range(1, 4);          // [1,2,3,4,"ret=4"]
$keyed = consume_keyed();              // ["a=1","b=2","c=3"]
echo "range=" . implode(",", $range) . "\n";
echo "keyed=" . implode(",", $keyed) . "\n";

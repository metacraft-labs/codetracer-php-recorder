<?php
/**
 * Closure coverage.
 *
 * Per recorder-test-requirements.md §2 PHP-specific row, closures
 * are required coverage.  Three flavours, all observably distinct
 * in the trace:
 *
 *   - `function () use (...)` — anonymous function with explicit
 *     captures (by value vs by reference).
 *   - `fn () =>` — arrow function (PHP 7.4+) with implicit by-value
 *     capture.
 *   - `Closure::bind` — re-bind $this / scope to expose private
 *     class state.
 *
 * Each closure is invoked at least once so the recorder gets a
 * function-entry frame for it (anonymous functions appear in the
 * function table as `{closure}` or `Class::{closure}` per PHP's
 * stringification convention).
 */

function make_counter_by_value(): array {
    $count = 0;
    $get = function () use ($count) { return $count; };
    $bump = function () use ($count) { return $count + 1; };
    return [$get, $bump];
}

function make_counter_by_ref(): array {
    $count = 0;
    $get = function () use (&$count) { return $count; };
    $bump = function () use (&$count) { $count++; return $count; };
    return [$get, $bump];
}

function arrow_double(int $x): int {
    $f = fn (int $y) => $y * 2 + $x;
    return $f(10);
}

class Box {
    private int $value;
    public function __construct(int $v) { $this->value = $v; }
}

function bind_closure(): int {
    $box = new Box(42);
    $reader = Closure::bind(function () { return $this->value; }, $box, Box::class);
    return $reader();
}

function run(): array {
    [$gv, $bv] = make_counter_by_value();
    $val_get_before = $gv();
    $val_bump1 = $bv();
    $val_bump2 = $bv();
    $val_get_after = $gv();

    [$gr, $br] = make_counter_by_ref();
    $ref_get_before = $gr();
    $ref_bump1 = $br();
    $ref_bump2 = $br();
    $ref_get_after = $gr();

    return [
        'val_get_before' => $val_get_before, // 0
        'val_bump1'      => $val_bump1,      // 1 (closure-local)
        'val_bump2'      => $val_bump2,      // 1 (still 1 — captured by value)
        'val_get_after'  => $val_get_after,  // 0
        'ref_get_before' => $ref_get_before, // 0
        'ref_bump1'      => $ref_bump1,      // 1
        'ref_bump2'      => $ref_bump2,      // 2 (mutated in place)
        'ref_get_after'  => $ref_get_after,  // 2
        'arrow'          => arrow_double(5), // 10*2 + 5 = 25
        'bound'          => bind_closure(),  // 42
    ];
}

$res = run();
foreach ($res as $k => $v) {
    echo "$k=$v\n";
}

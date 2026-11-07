<?php
/**
 * Focused recorder-semantics tests: one small program per rule, each
 * recorded through the real extension and decoded by `ct-print --full`.
 *
 * test_e2e.php covers whole programs per universal-checklist category;
 * this file pins the individual rules those programs rely on, so a
 * regression names the rule it broke:
 *
 *   - a step per executed statement (not per line, not per function);
 *   - the step's values are the frame's defined locals, with call
 *     arguments appearing once, on the callee's entry step;
 *   - thrown exceptions -- user and internal -- produce a Raise record,
 *     an Error I/O event, a Catch where handled, and an exception exit
 *     for each frame they escape; `exit()` is none of these;
 *   - generator stretches return what they yielded, and an exception
 *     escaping a generator exits its stretch by that exception.
 *
 * No mocks: real `php` subprocesses, the real trace writer, the canonical
 * reader.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/ct_harness.php';

/** Record an inline program; returns [projection, ct-print document, run]. */
function ct_record_source(string $name, string $source, array $args = []): array {
    $dir = sys_get_temp_dir() . '/ct_php_sem_' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    $path = "$dir/$name.php";
    file_put_contents($path, $source);
    $rec = ct_record($path, $args);
    [$events, $doc] = ct_decode_with_doc($rec['traceDir']);
    return [ct_project($events), $doc, $rec, $events];
}

/** Steps of one function, as [line, vars] pairs. */
function ct_steps_of(array $proj, string $fn): array {
    $out = [];
    foreach (($proj['Step'] ?? []) as $s) {
        if ($s['function'] === $fn) $out[] = [$s['line'], ct_step_vars($s)];
    }
    return $out;
}

echo "==> recorder semantics\n";

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------

echo "\n-- two statements on one line are two steps\n";
[$proj] = ct_record_source('two_on_a_line', <<<'PHP'
<?php
function f(): int {
    $a = 1; $b = 2;
    return $a + $b;
}
f();
PHP);
ct_assert_eq(
    [[2, []], [3, []], [3, [['a', ['Int', 1]]]], [4, [['a', ['Int', 1]], ['b', ['Int', 2]]]]],
    ct_steps_of($proj, 'f'),
    'f: entry step, then one step per statement -- line 3 twice');

echo "\n-- a loop body is stepped once per iteration\n";
[$proj] = ct_record_source('loop', <<<'PHP'
<?php
function sum3(): int {
    $s = 0;
    for ($i = 0; $i < 3; $i++) {
        $s += $i;
    }
    return $s;
}
sum3();
PHP);
$bodySteps = array_values(array_filter(ct_steps_of($proj, 'sum3'), fn($s) => $s[0] === 5));
ct_assert_eq(
    [
        [5, [['s', ['Int', 0]], ['i', ['Int', 0]]]],
        [5, [['s', ['Int', 0]], ['i', ['Int', 1]]]],
        [5, [['s', ['Int', 1]], ['i', ['Int', 2]]]],
    ],
    $bodySteps,
    'sum3: exactly 3 steps at the loop body, with the loop variable advancing');

echo "\n-- call arguments appear once, on the callee's entry step\n";
[$proj] = ct_record_source('args', <<<'PHP'
<?php
function twice(int $n): int {
    return $n * 2;
}
$k = 7;
$r = twice($k);
PHP);
ct_assert_eq(
    [[2, [['n', ['Int', 7]]]], [3, [['n', ['Int', 7]]]]],
    ct_steps_of($proj, 'twice'),
    'twice: n is on the entry step and the return statement, once each');
ct_assert_eq(
    [[1, []], [5, []], [6, [['k', ['Int', 7]]]]],
    ct_steps_of($proj, '<toplevel>'),
    '<toplevel>: the caller\'s step carries only its own variables');

echo "\n-- a reference is recorded as the value it refers to\n";
[$proj] = ct_record_source('refs', <<<'PHP'
<?php
function bump(int &$v): void {
    $v++;
}
function run(): int {
    $x = 41;
    bump($x);
    return $x;
}
run();
PHP);
ct_assert_eq(
    [[5, []], [6, []], [7, [['x', ['Int', 41]]]], [8, [['x', ['Int', 42]]]]],
    ct_steps_of($proj, 'run'),
    'run: the by-reference local reads as its integer value, before and after');
ct_assert_eq(
    [[2, [['v', ['Int', 41]]]], [3, [['v', ['Int', 41]]]], [4, [['v', ['Int', 42]]]]],
    ct_steps_of($proj, 'bump'),
    'bump: the by-reference parameter reads as its integer value');

// ---------------------------------------------------------------------------
// Exceptions
// ---------------------------------------------------------------------------

echo "\n-- an exception thrown by the engine is recorded like a user throw\n";
[$proj, $doc] = ct_record_source('internal_throw', <<<'PHP'
<?php
function divide(int $a, int $b): int {
    return intdiv($a, $b);
}
function safe(): string {
    try {
        return (string) divide(1, 0);
    } catch (DivisionByZeroError $e) {
        return "caught";
    }
}
safe();
PHP);
ct_assert_eq([['DivisionByZeroError', 'Division by zero', 'divide:3']],
    array_map(fn($r) => [$r['type'], $r['message'], $r['function'] . ':' . $r['line']], $proj['Raise'] ?? []),
    'internal_throw: intdiv() raising is a Raise in the calling frame');
ct_assert_eq([['DivisionByZeroError', 'safe']],
    array_map(fn($c) => [$c['type'], $c['function']], $proj['Catch'] ?? []),
    'internal_throw: the catch in safe() is a Catch');
ct_assert_eq([[11, '', 'Division by zero']],
    array_map(fn($e) => [$e['kind'], $e['metadata'], $e['content']], $proj['Event'] ?? []),
    'internal_throw: one Error event with the message');
$byFn = [];
foreach ($proj['Return'] as $r) {
    $byFn[$r['function']] = $r['exception'] === null ? null
        : [ct_value_brief($r['exception']), ct_type_name($doc, $r['exception'])];
}
ct_assert_eq(['divide' => [['Error', 'Division by zero'], 'DivisionByZeroError'], 'safe' => null], $byFn,
    'internal_throw: divide() exits by the exception, safe() returns');

echo "\n-- an exception escaping several frames exits each of them\n";
[$proj, $doc] = ct_record_source('propagate', <<<'PHP'
<?php
function inner(): void { throw new LogicException("deep"); }
function middle(): void { inner(); }
function outer(): string {
    try { middle(); } catch (LogicException $e) { return "ok"; }
    return "no";
}
outer();
PHP);
ct_assert_eq(
    [
        ['inner', ['Error', 'deep'], 'LogicException'],
        ['middle', ['Error', 'deep'], 'LogicException'],
        ['outer', null, null],
    ],
    array_map(fn($r) => [$r['function'],
        $r['exception'] === null ? null : ct_value_brief($r['exception']),
        ct_type_name($doc, $r['exception'])], $proj['Return']),
    'propagate: inner and middle exit by the exception, outer catches and returns');
ct_assert_eq(1, count($proj['Raise'] ?? []), 'propagate: one throw, one Raise');
ct_assert_eq([['LogicException', 'outer']],
    array_map(fn($c) => [$c['type'], $c['function']], $proj['Catch'] ?? []),
    'propagate: one Catch, in outer');

echo "\n-- exit() is not an exception\n";
[$proj, $doc, $rec] = ct_record_source('exit_in_function', <<<'PHP'
<?php
function leave(): void {
    echo "bye\n";
    exit(3);
}
leave();
echo "unreachable\n";
PHP);
ct_assert_eq(3, $rec['status'], 'exit_in_function: the program exits with 3');
ct_assert_eq(0, count($proj['Raise'] ?? []), 'exit_in_function: no Raise');
ct_assert_eq([[0, "bye\n"]],
    array_map(fn($e) => [$e['kind'], $e['content']], $proj['Event'] ?? []),
    'exit_in_function: the only event is the Write; exit() is not an Error');
ct_assert_eq([['leave', null]],
    array_map(fn($r) => [$r['function'], $r['exception']], $proj['Return'] ?? []),
    'exit_in_function: leave() does not exit by an exception');

echo "\n-- an exception escaping a generator exits that stretch\n";
[$proj, $doc] = ct_record_source('generator_throw', <<<'PHP'
<?php
function gen(): Generator {
    yield 1;
    throw new RuntimeException("gen-fail");
}
function consume(): string {
    $g = gen();
    try {
        foreach ($g as $v) { }
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return "done";
}
$r = consume();
PHP);
ct_assert_eq(
    [
        ['gen', ['Raw', 'Generator {}'], null],
        ['gen', ['Int', 1], null],
        ['gen', ['None', null], [['Error', 'gen-fail'], 'RuntimeException']],
        ['consume', ['Raw', 'gen-fail'], null],
    ],
    array_map(fn($r) => [$r['function'], ct_value_brief($r['return_value']),
        $r['exception'] === null ? null : [ct_value_brief($r['exception']), ct_type_name($doc, $r['exception'])]],
        $proj['Return']),
    'generator_throw: creation, the yield, then the stretch that throws');
ct_assert_eq([['RuntimeException', 'gen-fail', 'gen:4']],
    array_map(fn($r) => [$r['type'], $r['message'], $r['function'] . ':' . $r['line']], $proj['Raise'] ?? []),
    'generator_throw: the Raise is inside the generator, at the throw');

echo "\n-- a stretch that suspends at `yield from` yields nothing itself\n";
[$proj] = ct_record_source('yield_from', <<<'PHP'
<?php
function inner(): Generator { yield 1; yield 2; return 3; }
function outer(): Generator {
    $v = yield from inner();
    yield $v;
    yield from [10, 20];
}
$out = [];
foreach (outer() as $x) { $out[] = $x; }
PHP);
$retsOf = fn(string $fn) => array_values(array_map(fn($r) => ct_value_brief($r['return_value']),
    array_filter($proj['Return'], fn($r) => $r['function'] === $fn)));
ct_assert_eq(
    [['Raw', 'Generator {}'], ['Int', 1], ['Int', 2], ['Int', 3]],
    $retsOf('inner'),
    'yield_from: the delegate\'s stretches yield 1, 2 and return 3');
// No value is projected as None (the VoidReturn marker).
ct_assert_eq(
    [['Raw', 'Generator {}'], ['None', null], ['Int', 3], ['None', null], ['Raw', 'null']],
    $retsOf('outer'),
    'yield_from: outer\'s delegating stretches return no value; `yield $v` returns 3');

echo "\n-- a yield whose value is unused delivers nothing\n";
[$proj] = ct_record_source('yield_unused', <<<'PHP'
<?php
function g(): Generator {
    yield 1;
    yield 2;
}
foreach (g() as $v) { }
PHP);
$names = [];
foreach (($proj['Step'] ?? []) as $s) {
    foreach ($s['vars'] as $v) $names[$v['name']] = true;
}
ct_assert_true(!isset($names['<sent>']),
    'yield_unused: no <sent> value when the yield expression is a statement',
    'variables seen: ' . json_encode(array_keys($names)));

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n" . str_repeat('=', 64) . "\n";
$total = $GLOBALS['__ct_tests'];
$pass  = $GLOBALS['__ct_passed'];
$fail  = $GLOBALS['__ct_failed'];
$skip  = $GLOBALS['__ct_skipped'];
echo "Tests: $total, Passed: $pass, Failed: $fail, Skipped: $skip\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['__ct_failures'] as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "ALL RECORDER-SEMANTICS TESTS PASSED\n";

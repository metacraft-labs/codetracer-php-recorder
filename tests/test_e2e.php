<?php
/**
 * End-to-end recorder coverage suite.
 *
 * For every program under tests/programs/*.php this harness:
 *
 *   1. Records the program through the recorder's normal entry point
 *      (`php -d extension=ext/modules/codetracer.so program.php`).
 *   2. Decodes the produced `<program>.ct` CTFS bundle into a JSON
 *      event stream via `ct-print --json-events`.
 *   3. Asserts on the **exact event counts**, **exact event order**,
 *      **exact decoded values**, and **exact ValueRecord variant
 *      tags** — per `metacraft-specs/policies/recorder-test-
 *      requirements.md` §1 ("Maximum assertion strength").
 *
 * Decoder note: the recorder now writes a CTFS V4 multi-stream `.ct`
 * bundle (via the Nim FFI in `codetracer-trace-format-nim`).  The
 * `ct-print --json-events` schema uses lowercase `type` discriminators
 * (`{type:"step", ...}`, `{type:"call", ...}`, ...) and folds Returns
 * into call records' `exit_step`, so this file translates the new
 * shape back into the legacy projection-friendly form
 * (`{Step:{...}}`, `{Call:{...}}`, `{Return:{...}}`, ...) — see
 * `ct_translate_to_legacy()`.  Test bodies still assert on the legacy
 * shape; when they migrate to the native CTFS shape the translator
 * can be removed.
 *
 * No test in this file is a no-op or substring-only check.  When the
 * recorder is missing a feature the test EITHER asserts on the
 * present-day shape (golden-pinned, with a RECORDER BUG note) OR
 * skips with a `// SKIP:` line and a parallel ignored test capturing
 * the spec-compliant expectation, so the test surfaces the moment
 * the recorder catches up.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/ct_harness.php';


// ---------------------------------------------------------------------------
// Sanity: the extension must build and the decoder must exist.  Both
// are hard preconditions for the rest of this file.  We DON'T silently
// skip — these are environment bugs that need fixing.
// ---------------------------------------------------------------------------

echo "==> Preflight checks\n";

if (!file_exists(ct_extension_path())) {
    echo "  FAIL: codetracer.so not built at " . ct_extension_path() . "\n";
    echo "        Run `just build` first.\n";
    exit(1);
}
ct_pass("extension binary exists");

if (!is_executable(ct_print_path())) {
    // Hard fail rather than skip.  The decoder is required for the
    // policy-mandated EXACT-event-content assertions; without it the
    // suite degrades to substring-only checks (forbidden by §1).
    echo "  FAIL: ct-print not built at " . ct_print_path() . "\n";
    echo "        Build it with: cd " . ct_trace_format_nim_dir() . " && nimble buildCtPrint\n";
    exit(1);
}
ct_pass("ct-print CTFS decoder exists");

// ---------------------------------------------------------------------------
// Per-program tests
// ---------------------------------------------------------------------------

$programs_dir = __DIR__ . '/programs';

// =========================================================================
// flow_test.php — canonical cross-recorder fixture (recorder-test-
//                 requirements §4).
// =========================================================================
//
// Spec target: a=10, b=32, sum_val=42, doubled=84, final_result=94.
// The recorder MUST surface these five (name, i64) pairs as step
// variable values.
//
// The recorder steps once per executed statement (the compiler's
// `EXT_STMT` marker), plus the call's entry step at the `function` line,
// and every step carries the frame's defined local variables as they
// stand when the step is reached — the same shape the Python and Ruby
// recorders produce from their per-line events.
// =========================================================================

echo "\n==> flow_test.php (canonical §4 fixture)\n";
$rec = ct_record($programs_dir . '/flow_test.php');
ct_assert_eq(0, $rec['status'], 'flow_test php exit status');
ct_assert_true(str_contains($rec['stdout'], "flow_test result: 94"),
    'flow_test stdout has expected result line',
    'stdout was: ' . $rec['stdout']);

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT counts of each event kind.  Tightening any of these is an
// intentional regression detector — extra/missing events == bug.
ct_assert_eq(1, count($proj['Path'] ?? []), 'flow_test: 1 path registered');
ct_assert_eq(2, count($proj['Function'] ?? []), 'flow_test: 2 functions registered (<toplevel>, compute)');
ct_assert_eq(2, count($proj['Call'] ?? []), 'flow_test: 2 calls (<toplevel>, compute)');
ct_assert_eq(1, count($proj['Return'] ?? []), 'flow_test: 1 return (compute) -- <toplevel> has no Return');
ct_assert_eq(1, count($proj['Event'] ?? []), 'flow_test: 1 io event (the echo)');
ct_assert_eq(0, count($proj['Raise'] ?? []), 'flow_test: no Raise records');

// EXACT step sequence: the entry step `start` emits at line 1, one step
// per executed statement, and compute()'s entry step at its `function`
// line.  The `function compute()` declaration is hoisted at compile time
// and executes no statement, so it has no step.
ct_assert_eq(
    [
        '<toplevel>:1',   // the entry step trace_writer_start emits
        '<toplevel>:32',  // $result = compute();
        'compute:23',     // entry step, at the `function` line
        'compute:24',     // $a = 10;
        'compute:25',     // $b = 32;
        'compute:26',     // $sum_val = $a + $b;
        'compute:27',     // $doubled = $sum_val * 2;
        'compute:28',     // $final_result = $doubled + $a;
        'compute:29',     // return $final_result;
        '<toplevel>:36',  // echo ...
    ],
    ct_step_positions($proj),
    'flow_test: one step per executed statement, in execution order');

// EXACT values at every step: a statement's step shows the locals as they
// stand BEFORE the statement runs, so each binding first appears at the
// step after its assignment, and never at a step where it is undefined.
$a = ['a', ['Int', 10]];
$b = ['b', ['Int', 32]];
$sum = ['sum_val', ['Int', 42]];
$dbl = ['doubled', ['Int', 84]];
$fin = ['final_result', ['Int', 94]];
ct_assert_eq(
    [
        [],
        [],
        [],
        [],
        [$a],
        [$a, $b],
        [$a, $b, $sum],
        [$a, $b, $sum, $dbl],
        [$a, $b, $sum, $dbl, $fin],
        [['result', ['Int', 94]]],
    ],
    array_map('ct_step_vars', $proj['Step'] ?? []),
    'flow_test: exact variables at each step');

// The canonical §4 assertion, stated on its own: each of the five
// (name, i64) pairs surfaces as a step variable, decoded as Int.
$seen = [];
foreach (($proj['Step'] ?? []) as $st) {
    foreach (ct_step_vars($st) as [$name, $val]) {
        if ($val[0] === 'Int') $seen[$name] = $val[1];
    }
}
foreach (['a' => 10, 'b' => 32, 'sum_val' => 42, 'doubled' => 84, 'final_result' => 94] as $name => $want) {
    ct_assert_eq($want, $seen[$name] ?? null, "flow_test: canonical variable $name = $want");
}

// EXACT function table (writer-assignment order).
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(['<toplevel>', 'compute'], $fnames, 'flow_test: function table');

// Path table contains the source file.
$paths = $proj['Path'] ?? [];
ct_assert_true(
    count($paths) === 1 && str_ends_with($paths[0], '/programs/flow_test.php'),
    'flow_test: path table has flow_test.php',
    'paths=' . json_encode($paths));

// EXACT decoded return value of compute().
$rets = $proj['Return'];
$rv = $rets[0]['return_value'];
$rvi = ct_assert_value_int_kind($rv, 'flow_test: compute return');
ct_assert_eq(94, $rvi, 'flow_test: compute returns 94 (decoded as Int)');

// EXACT io_event content, attributed to the echo statement's step.
$ioev = $proj['Event'][0];
ct_assert_eq(0, $ioev['kind'], 'flow_test: io_event kind = ELK_WRITE (0)');
ct_assert_eq('stdout', $ioev['metadata'], 'flow_test: io_event metadata = stdout');
ct_assert_eq("flow_test result: 94\n", $ioev['content'], 'flow_test: io_event content');
$ioStep = ct_step_at($proj, $ioev['step_id']);
ct_assert_eq('<toplevel>:36', $ioStep['function'] . ':' . $ioStep['line'],
    'flow_test: the echo output belongs to the echo statement\'s step');

// =========================================================================
// nested_calls.php — 4-deep chain + recursive factorial.
// =========================================================================

echo "\n==> nested_calls.php (>=3-deep + recursion)\n";
$rec = ct_record($programs_dir . '/nested_calls.php');
ct_assert_eq(0, $rec['status'], 'nested_calls php exit status');
ct_assert_true(str_contains($rec['stdout'], 'nested=113 fact=120'),
    'nested_calls stdout');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    ['<toplevel>', 'compute', 'outer', 'middle', 'inner', 'factorial'],
    $fnames,
    'nested_calls: function table is in writer-assignment order');

// EXACT call count.
// 1 toplevel + 1 compute + 1 outer + 1 middle + 1 inner + 5 factorial frames = 10.
ct_assert_eq(10, count($proj['Call'] ?? []), 'nested_calls: 10 call events');

// EXACT return-value sequence (LIFO order from the recursion + the linear chain).
$ret_values = [];
foreach ($proj['Return'] as $r) {
    $rv = $r['return_value'];
    $ret_values[] = ct_assert_value_int_kind($rv, 'nested_calls: return');
}
// Order: inner=3, middle=13, outer=113, compute=113, then factorial 1,2,6,24,120.
ct_assert_eq([3, 13, 113, 113, 1, 2, 6, 24, 120], $ret_values,
    'nested_calls: returns in LIFO+invocation order');

// =========================================================================
// control_flow.php — if/elseif/else, while, do-while, for, switch,
//                    match, break, continue.
// =========================================================================

echo "\n==> control_flow.php (every PHP control construct)\n";
$rec = ct_record($programs_dir . '/control_flow.php');
ct_assert_eq(0, $rec['status'], 'control_flow php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.  Order = first invocation order (each
// function is registered the first time `zend_execute_ex` enters
// it).  classify is invoked first because run_control_flow's
// first call is classify(-5).
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    ['<toplevel>', 'run_control_flow', 'classify',
     'while_sum', 'do_while_sum', 'for_sum',
     'break_continue', 'switch_select', 'match_select'],
    $fnames,
    'control_flow: function table (writer-assignment order)');

// classify is called THREE times, switch_select TWICE, match_select TWICE,
// each loop function ONCE, run_control_flow ONCE, plus toplevel = 13 calls.
ct_assert_eq(13, count($proj['Call'] ?? []),
    'control_flow: 13 call events (3 classify + 2 switch + 2 match + 4 loops + 1 run + 1 toplevel)');

// EXACT decoded return values (in observed order — see returns
// dump in test_e2e.php's authoring run).  The "Array(11)" return
// is the run_control_flow associative array; it surfaces as Raw
// because the recorder's `serialize_return_zval` does not deep-
// encode arrays (RECORDER BUG: array contents not captured).
$rets = $proj['Return'];
ct_assert_eq(12, count($rets), 'control_flow: 12 returns (one per non-toplevel call)');

// String returns from classify / switch_select / match_select must
// come back as Raw (the recorder uses TK_STRING + value_repr).
$rv0 = $rets[0]['return_value'];
ct_assert_eq('negative', ct_assert_value_raw_kind($rv0, 'control_flow: classify(-5)'),
    'control_flow: classify(-5) returns "negative"');
$rv1 = $rets[1]['return_value'];
ct_assert_eq('zero', ct_assert_value_raw_kind($rv1, 'control_flow: classify(0)'),
    'control_flow: classify(0) returns "zero"');
$rv2 = $rets[2]['return_value'];
ct_assert_eq('positive', ct_assert_value_raw_kind($rv2, 'control_flow: classify(7)'),
    'control_flow: classify(7) returns "positive"');

// Loop sums: while=10, do-while=6, for=15.
ct_assert_eq(10, ct_assert_value_int_kind($rets[3]['return_value'], 'while_sum'),
    'control_flow: while_sum(5) = 10');
ct_assert_eq(6,  ct_assert_value_int_kind($rets[4]['return_value'], 'do_while_sum'),
    'control_flow: do_while_sum(4) = 6');
ct_assert_eq(15, ct_assert_value_int_kind($rets[5]['return_value'], 'for_sum'),
    'control_flow: for_sum(6) = 15');
// break_continue: 0+1+3+4 = 8 (skip 2 via continue, break at 5).
ct_assert_eq(8, ct_assert_value_int_kind($rets[6]['return_value'], 'break_continue'),
    'control_flow: break_continue(10) = 8');

// switch_select(1) = "one"; switch_select(99) = "other".
ct_assert_eq('one',   ct_assert_value_raw_kind($rets[7]['return_value'], 'switch_select(1)'),
    'control_flow: switch_select(1) = "one"');
ct_assert_eq('other', ct_assert_value_raw_kind($rets[8]['return_value'], 'switch_select(99)'),
    'control_flow: switch_select(99) = "other"');
// match_select(2) = "two"; match_select(99) = "other".
ct_assert_eq('two',   ct_assert_value_raw_kind($rets[9]['return_value'], 'match_select(2)'),
    'control_flow: match_select(2) = "two"');
ct_assert_eq('other', ct_assert_value_raw_kind($rets[10]['return_value'], 'match_select(99)'),
    'control_flow: match_select(99) = "other"');

// run_control_flow's return is the assoc array — surfaced as Raw "Array(11)".
// RECORDER BUG: array contents not deep-encoded; the recorder's
// `serialize_return_zval` for IS_ARRAY emits only the element count.
// Tracking: extend the FFI to support `register_return_*` for
// compound ValueRecord variants (Sequence / Tuple / Struct).
$rv11 = $rets[11]['return_value'];
ct_assert_eq('Array(11)', ct_assert_value_raw_kind($rv11, 'control_flow: run return'),
    'control_flow: run_control_flow returns Raw("Array(11)") (RECORDER BUG: arrays not deep-encoded)');

// EXACT io_event count: one echo per result key (11 keys).
ct_assert_eq(11, count($proj['Event'] ?? []), 'control_flow: 11 io_events');

// =========================================================================
// classes.php — class, constructor, inheritance, $this, static
//               method, parent::.
// =========================================================================

echo "\n==> classes.php (class / inheritance / \$this)\n";
$rec = ct_record($programs_dir . '/classes.php');
ct_assert_eq(0, $rec['status'], 'classes php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.  PHP 8 emits class methods with the bare
// method name (no qualifier) — both Greeter::greet and LoudGreeter::greet
// register as `greet` (the recorder uses func->common.function_name
// which is the bare zend_string).  The two `greet` overloads collide
// under the same function-table slot — RECORDER BUG: the recorder
// does not include the class qualifier (scope) in the function name.
//
// Tracking: extend `codetracer_execute_ex` to prepend
// `func->common.scope->name` when present, mirroring the JS
// recorder's `Class::method` formatting (handoff entry 1.38).
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    ['<toplevel>', 'run', '__construct', 'greet', 'shout'],
    $fnames,
    'classes: function table (RECORDER BUG: no class qualifier on method names)');

// EXACT decoded returns:
//   __construct (Greeter)  -> None
//   __construct (LoudGreeter inherits) -> None
//   Greeter::greet("World") -> "Hello, World!"
//   parent::greet("World") inside LoudGreeter::greet -> "Hi, World!"
//   self::shout($base) -> "HI, WORLD!"
//   LoudGreeter::greet("World") -> "HI, WORLD!"
//   Greeter::shout("done") -> "DONE"
//   run() -> Array(3)
$rets = $proj['Return'];
ct_assert_eq(8, count($rets), 'classes: 8 returns');

ct_assert_eq('None', $rets[0]['return_value']['kind'] ?? '', 'classes: Greeter::__construct return is None');
ct_assert_eq('None', $rets[1]['return_value']['kind'] ?? '', 'classes: LoudGreeter::__construct return is None');
ct_assert_eq('Hello, World!', ct_assert_value_raw_kind($rets[2]['return_value'], 'classes: Greeter::greet'),
    'classes: Greeter::greet("World") = "Hello, World!"');
ct_assert_eq('Hi, World!', ct_assert_value_raw_kind($rets[3]['return_value'], 'classes: parent::greet'),
    'classes: parent::greet("World") inside LoudGreeter = "Hi, World!"');
ct_assert_eq('HI, WORLD!', ct_assert_value_raw_kind($rets[4]['return_value'], 'classes: self::shout'),
    'classes: self::shout($base) = "HI, WORLD!"');
ct_assert_eq('HI, WORLD!', ct_assert_value_raw_kind($rets[5]['return_value'], 'classes: LoudGreeter::greet'),
    'classes: LoudGreeter::greet("World") = "HI, WORLD!"');
ct_assert_eq('DONE', ct_assert_value_raw_kind($rets[6]['return_value'], 'classes: Greeter::shout'),
    'classes: Greeter::shout("done") = "DONE"');
ct_assert_eq('Array(3)', ct_assert_value_raw_kind($rets[7]['return_value'], 'classes: run'),
    'classes: run() returns Raw("Array(3)") (RECORDER BUG: arrays not deep-encoded)');

// =========================================================================
// exceptions.php — try/catch/finally, multiple types, re-throw.
// =========================================================================

echo "\n==> exceptions.php (try/catch/finally + re-throw)\n";
$rec = ct_record($programs_dir . '/exceptions.php');
ct_assert_eq(0, $rec['status'], 'exceptions php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    ['<toplevel>', 'classify_error', 'raise_app', 'raise_net', 'raise_fmt', 'with_finally'],
    $fnames,
    'exceptions: function table');

// EXACT return sequence.  A raise_*() frame that throws exits by the
// exception: its call record carries the exception, and no return value
// (the VoidReturn marker, projected as None).
$rets = $proj['Return'];

// Order:
//   raise_app   -> None  (thrown)
//   classify_error -> "app:app-failure"
//   raise_net   -> None  (thrown)
//   classify_error -> "net:net-failure"
//   raise_fmt   -> None  (thrown)
//   classify_error -> "fmt:fmt-failure"
//   raise_app inside with_finally -> None (thrown)
//   with_finally -> "finally1->caught:app-failure"
ct_assert_eq(8, count($rets), 'exceptions: 8 returns');
ct_assert_eq('None', $rets[0]['return_value']['kind'], 'exceptions: raise_app Return is None (thrown)');
ct_assert_eq('app:app-failure', ct_assert_value_raw_kind($rets[1]['return_value'], 'classify_error[app]'),
    'exceptions: classify_error returns "app:app-failure"');
ct_assert_eq('None', $rets[2]['return_value']['kind'], 'exceptions: raise_net Return is None (thrown)');
ct_assert_eq('net:net-failure', ct_assert_value_raw_kind($rets[3]['return_value'], 'classify_error[net]'),
    'exceptions: classify_error returns "net:net-failure"');
ct_assert_eq('None', $rets[4]['return_value']['kind'], 'exceptions: raise_fmt Return is None (thrown)');
ct_assert_eq('fmt:fmt-failure', ct_assert_value_raw_kind($rets[5]['return_value'], 'classify_error[fmt]'),
    'exceptions: classify_error returns "fmt:fmt-failure"');
ct_assert_eq('None', $rets[6]['return_value']['kind'], 'exceptions: raise_app inside with_finally Return is None (thrown)');
ct_assert_eq('finally1->caught:app-failure',
    ct_assert_value_raw_kind($rets[7]['return_value'], 'with_finally'),
    'exceptions: with_finally returns "finally1->caught:app-failure"');

[$events, $doc] = ct_decode_with_doc($rec['traceDir']);
$proj = ct_project($events);
$rets = $proj['Return'];

// EXACT exception carried by each call record: the four raise_*() frames
// exit by their exception, decoded as ValueRecord::Error whose type is the
// exception's class; every other frame returned normally.
$exits = [];
foreach ($rets as $r) {
    $exits[] = $r['exception'] === null ? null : [
        $r['function'],
        ct_value_brief($r['exception']),
        ct_type_name($doc, $r['exception']),
    ];
}
ct_assert_eq(
    [
        ['raise_app', ['Error', 'app-failure'], 'AppError'],
        null,
        ['raise_net', ['Error', 'net-failure'], 'NetworkError'],
        null,
        ['raise_fmt', ['Error', 'fmt-failure'], 'FormatError'],
        null,
        ['raise_app', ['Error', 'app-failure'], 'AppError'],
        null,
    ],
    $exits,
    'exceptions: exactly the throwing frames exit by their exception');

// EXACT Raise records: one per throw, in order, each inside the frame that
// threw and at the throw statement.  The re-throw in with_finally() is a
// throw of its own.
$raises = array_map(fn($r) => [$r['type'], $r['message'], $r['function'] . ':' . $r['line']],
    $proj['Raise'] ?? []);
ct_assert_eq(
    [
        ['AppError', 'app-failure', 'raise_app:22'],
        ['NetworkError', 'net-failure', 'raise_net:23'],
        ['FormatError', 'fmt-failure', 'raise_fmt:24'],
        ['AppError', 'app-failure', 'raise_app:22'],
        ['AppError', 'app-failure', 'with_finally:46'],
    ],
    $raises,
    'exceptions: one Raise per throw, at the throw, with type and message');

// EXACT Catch records: one per handler that took an exception, in the frame
// whose `catch` matched, naming the caught exception's class.  A `catch`
// clause that does not match records nothing, and `finally` is not a catch.
$catches = array_map(fn($c) => [$c['type'], $c['function']], $proj['Catch'] ?? []);
ct_assert_eq(
    [
        ['AppError', 'classify_error'],
        ['NetworkError', 'classify_error'],
        ['FormatError', 'classify_error'],
        ['AppError', 'with_finally'],
        ['AppError', 'with_finally'],
    ],
    $catches,
    'exceptions: one Catch per handled exception, in the catching frame');

// The exec order of the exception records: every Raise precedes the Catch
// that handles it, and each Catch comes after the thrower has exited.
$order = [];
foreach ($events as $ev) {
    $k = array_key_first($ev);
    if ($k === 'Raise') $order[] = 'R';
    if ($k === 'Catch') $order[] = 'C';
    if ($k === 'Return' && $ev['Return']['exception'] !== null) $order[] = 'X';
}
ct_assert_eq(['R', 'X', 'C', 'R', 'X', 'C', 'R', 'X', 'C', 'R', 'X', 'C', 'R', 'C'], $order,
    'exceptions: raise, exit by exception, then catch -- in that order');

// EXACT Error I/O events: one per thrown exception (recorder-test-
// requirements §2: a function that catches an exception must produce an
// EventKindError event).  As in the Ruby recorder the content is the
// exception's message and the metadata is empty; each is attributed to the
// throw statement's step.  The four `echo` lines are Write events.
$errs = [];
$writes = [];
foreach (($proj['Event'] ?? []) as $ev) {
    if ($ev['kind'] === CT_EVENT_LOG_KINDS['Error']) {
        $st = ct_step_at($proj, $ev['step_id']);
        $errs[] = [$ev['metadata'], $ev['content'], $st['function'] . ':' . $st['line']];
    } elseif ($ev['kind'] === CT_EVENT_LOG_KINDS['Write']) {
        $writes[] = $ev['content'];
    } else {
        ct_fail('exceptions: io event kind', 'unexpected kind ' . $ev['kind']);
    }
}
ct_assert_eq(
    [
        ['', 'app-failure', 'raise_app:22'],
        ['', 'net-failure', 'raise_net:23'],
        ['', 'fmt-failure', 'raise_fmt:24'],
        ['', 'app-failure', 'raise_app:22'],
        ['', 'app-failure', 'with_finally:46'],
    ],
    $errs,
    'exceptions: one Error event per thrown exception, at the throw');
ct_assert_eq(
    ["app=app:app-failure\n", "net=net:net-failure\n", "fmt=fmt:fmt-failure\n",
     "fin=finally1->caught:app-failure\n"],
    $writes,
    'exceptions: the four echo lines are Write events');

// Verify that the unhandled-throw branch terminates the program with
// non-zero exit + the exception message on stderr.  This exercises
// recorder-test-requirements.md §2 "raise without handler
// (program-terminating)" -- the recorder should still produce a
// trace for the partial execution.
$traceDir2 = sys_get_temp_dir() . '/ct_php_e2e_' . bin2hex(random_bytes(6));
mkdir($traceDir2, 0755, true);
$env2 = [
    'CODETRACER_ENABLED' => '1',
    'CODETRACER_TRACE_DIR' => $traceDir2,
    'LD_LIBRARY_PATH' => ct_ld_library_path(),
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    'HOME' => getenv('HOME') ?: '/tmp',
];
$descs = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc2 = proc_open([
    'php', '-d', 'extension=' . ct_extension_path(),
    $programs_dir . '/exceptions.php', '--terminate',
], $descs, $pipes2, ct_repo_root(), $env2);
fclose($pipes2[0]);
$so2 = stream_get_contents($pipes2[1]); fclose($pipes2[1]);
$se2 = stream_get_contents($pipes2[2]); fclose($pipes2[2]);
$st2 = proc_close($proc2);
ct_assert_true($st2 !== 0, 'exceptions: --terminate exits non-zero',
    "actual status=$st2");
// PHP's CLI default routes uncaught-exception fatals to STDOUT (the
// `display_errors=stdout` default, not stderr).  We assert on the
// combined output to stay robust to the ini's `display_errors` mode.
$combined = $so2 . $se2;
ct_assert_true(str_contains($combined, 'unhandled'),
    'exceptions: --terminate output contains the unhandled exception message',
    'combined output was: ' . $combined);
$cts2 = glob($traceDir2 . '/*.ct');
ct_assert_true(!empty($cts2),
    'exceptions: --terminate still produced a .ct CTFS bundle (recorder runs to RSHUTDOWN)');

// The uncaught exception is recorded like a caught one -- a Raise at the
// throw, an Error event with its message -- and the frame it escapes from
// exits by it.  Nothing catches it, so there is no Catch record.
[$events2, $doc2] = ct_decode_with_doc($traceDir2);
$proj2 = ct_project($events2);
ct_assert_eq(
    [
        ['AppError', 'app-failure', 'raise_app:22'],
        ['NetworkError', 'net-failure', 'raise_net:23'],
        ['FormatError', 'fmt-failure', 'raise_fmt:24'],
        ['AppError', 'app-failure', 'raise_app:22'],
        ['AppError', 'app-failure', 'with_finally:46'],
        ['RuntimeException', 'unhandled', 'will_terminate:70'],
    ],
    array_map(fn($r) => [$r['type'], $r['message'], $r['function'] . ':' . $r['line']], $proj2['Raise'] ?? []),
    'exceptions --terminate: the uncaught throw is a Raise at the throw');
ct_assert_eq(5, count($proj2['Catch'] ?? []),
    'exceptions --terminate: the uncaught exception adds no Catch');
$errs2 = array_values(array_filter($proj2['Event'] ?? [],
    fn($e) => $e['kind'] === CT_EVENT_LOG_KINDS['Error']));
ct_assert_eq(6, count($errs2), 'exceptions --terminate: 6 Error events');
$lastErr = end($errs2);
if ($lastErr === false) {
    ct_fail('exceptions --terminate: the uncaught exception is an Error event at its throw',
        'the recording has no Error event');
} else {
    $lastErrStep = ct_step_at($proj2, $lastErr['step_id']);
    ct_assert_eq(['', 'unhandled', 'will_terminate:70'],
        [$lastErr['metadata'], $lastErr['content'], $lastErrStep['function'] . ':' . $lastErrStep['line']],
        'exceptions --terminate: the uncaught exception is an Error event at its throw');
}
$lastRet = end($proj2['Return']);
ct_assert_eq(['will_terminate', ['Error', 'unhandled'], 'RuntimeException'],
    [$lastRet['function'],
     $lastRet['exception'] === null ? null : ct_value_brief($lastRet['exception']),
     ct_type_name($doc2, $lastRet['exception'])],
    'exceptions --terminate: will_terminate exits by the uncaught exception');

// =========================================================================
// closures.php — Closure::bind, arrow functions, value vs ref captures.
// =========================================================================

echo "\n==> closures.php (Closure::bind, arrows, captures)\n";
$rec = ct_record($programs_dir . '/closures.php');
ct_assert_eq(0, $rec['status'], 'closures php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.  PHP 8 names anonymous closures using the
// `{closure:enclosing():line}` formatting.  This is the canonical
// PHP-8 reflection name (see the `php_reflection.c` change in
// php-src 70e4c43a).
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    [
        '<toplevel>', 'run',
        'make_counter_by_value',
        '{closure:make_counter_by_value():24}',
        '{closure:make_counter_by_value():25}',
        'make_counter_by_ref',
        '{closure:make_counter_by_ref():31}',
        '{closure:make_counter_by_ref():32}',
        'arrow_double',
        '{closure:arrow_double():37}',
        'bind_closure',
        '__construct',
        '{closure:bind_closure():48}',
    ],
    $fnames,
    'closures: function table includes anonymous closures + arrow + Box::__construct');

// EXACT decoded return values (Int variants).  Pull every Int-kind
// Return in observed order and check the decoded i.
$ret_ints = [];
foreach ($proj['Return'] as $r) {
    $rv = $r['return_value'];
    if (($rv['kind'] ?? null) === 'Int') {
        $ret_ints[] = $rv['i'];
    }
}
// Order from a recording authoring run:
//   val_get_before=0, val_bump1=1, val_bump2=1, val_get_after=0,
//   ref_get_before=0, ref_bump1=1, ref_bump2=2, ref_get_after=2,
//   arrow inner=25, arrow_double=25, bind reader=42, bind_closure=42.
ct_assert_eq([0, 1, 1, 0, 0, 1, 2, 2, 25, 25, 42, 42], $ret_ints,
    'closures: Int returns capture by-value vs by-reference semantics ' .
    '(by-value bumps stay 1, by-ref bumps grow to 2)');

// =========================================================================
// generators.php — yield, key=>value, foreach over generator,
//                  Generator::getReturn().
// =========================================================================

echo "\n==> generators.php (yield + foreach + getReturn)\n";
$rec = ct_record($programs_dir . '/generators.php');
ct_assert_eq(0, $rec['status'], 'generators php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

// EXACT function table.
$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(
    ['<toplevel>', 'consume_range', 'int_range', 'consume_keyed', 'keyed'],
    $fnames,
    'generators: function table');

// EXACT call count.  Each foreach resumption invokes
// zend_execute_ex on the generator function, so the recorder sees
// one Call per advance:
//   consume_range : 1 call
//   int_range     : 5 invocations (lo=1 to hi=4 = 4 yields + 1 final advance that hits return)
//   consume_keyed : 1 call
//   keyed         : 4 invocations (3 yields + 1 final advance)
//   <toplevel>    : 1
//   plus the int_range/keyed initial setup (when the generator object
//   is constructed PHP fires zend_execute_ex once)
//
// Empirically (from an authoring recording): 14 Call events.  Pin
// the exact count -- if the recorder starts double-counting
// resumptions or skipping the final advance, this fails.
ct_assert_eq(14, count($proj['Call'] ?? []),
    'generators: 14 call events (1 toplevel + 1 consume_range + 6 int_range + 1 consume_keyed + 5 keyed)');

// EXACT decoded io_events: two echoes.
ct_assert_eq(2, count($proj['Event'] ?? []), 'generators: 2 io_events');
ct_assert_eq("range=1,2,3,4,ret=4\n", $proj['Event'][0]['content'],
    'generators: range echo content');
ct_assert_eq("keyed=a=1,b=2,c=3\n", $proj['Event'][1]['content'],
    'generators: keyed echo content');

// A generator frame is one call per stretch of execution, as in the
// Python recorder: calling the generator function binds its arguments and
// returns the Generator object (PHP runs the frame up to its
// GENERATOR_CREATE), every resumption opens a NEW call of the generator
// function, and every suspension returns the value it yielded.  The final
// resumption returns the generator's own return value.
$callsOf = function (array $proj, string $fn): array {
    return array_values(array_filter($proj['Call'] ?? [], fn($c) => $c['function'] === $fn));
};
$returnsOf = function (array $proj, string $fn): array {
    return array_values(array_map(fn($r) => ct_value_brief($r['return_value']),
        array_filter($proj['Return'] ?? [], fn($r) => $r['function'] === $fn)));
};
// Each call's entry step: [line, variables at that step].
$entriesOf = function (array $proj, string $fn) use ($callsOf): array {
    return array_map(function ($c) use ($proj) {
        $st = ct_step_at($proj, $c['entry_step']);
        return [$st['line'], ct_step_vars($st)];
    }, $callsOf($proj, $fn));
};

ct_assert_eq(
    [['Raw', 'Generator {}'], ['Int', 1], ['Int', 2], ['Int', 3], ['Int', 4], ['Int', 4]],
    $returnsOf($proj, 'int_range'),
    'generators: int_range returns the Generator, then each yielded value, then its return value');
ct_assert_eq(
    [['Raw', 'Generator {}'], ['Int', 1], ['Int', 2], ['Int', 3], ['Raw', 'null']],
    $returnsOf($proj, 'keyed'),
    'generators: keyed returns the Generator, then each yielded value, then null');

// Where each stretch starts: the creating call at the `function` line with
// the bound arguments; the first resumption at the start of the body; every
// later resumption at the `yield` it was suspended at, with the locals as
// they stood there.
$lo = ['lo', ['Int', 1]];
$hi = ['hi', ['Int', 4]];
ct_assert_eq(
    [
        [19, [$lo, $hi]],
        [19, [$lo, $hi]],
        [21, [$lo, $hi, ['i', ['Int', 1]]]],
        [21, [$lo, $hi, ['i', ['Int', 2]]]],
        [21, [$lo, $hi, ['i', ['Int', 3]]]],
        [21, [$lo, $hi, ['i', ['Int', 4]]]],
    ],
    $entriesOf($proj, 'int_range'),
    'generators: int_range entry steps (line, locals) across creation and resumptions');
ct_assert_eq(
    [[26, []], [26, []], [27, []], [28, []], [29, []]],
    $entriesOf($proj, 'keyed'),
    'generators: keyed entry steps resume at the yield that suspended it');

// Every yield is a step of the resumed call, so the consumer's loop body
// and the generator's body alternate in the step stream.
$yieldSteps = array_values(array_filter(ct_step_positions($proj),
    fn($p) => $p === 'int_range:21'));
ct_assert_eq(8, count($yieldSteps),
    'generators: 4 yield statements, each stepped once and resumed once');

// =========================================================================
// generator_send.php — values sent INTO a generator.
// =========================================================================

echo "\n==> generator_send.php (Generator::send)\n";
$rec = ct_record($programs_dir . '/generator_send.php');
ct_assert_eq(0, $rec['status'], 'generator_send php exit status');

$events = ct_decode_events($rec['traceDir']);
$proj = ct_project($events);

$fnames = array_map(fn($f) => $f['name'], $proj['Function'] ?? []);
ct_assert_eq(['<toplevel>', 'drive_accumulator', 'accumulator'], $fnames,
    'generator_send: function table');
// <toplevel> + drive_accumulator + accumulator's creation and its four
// resumptions (current(), send(5), send(7), send(null)).
ct_assert_eq(7, count($proj['Call'] ?? []), 'generator_send: 7 call events');
ct_assert_eq(
    [['Raw', 'Generator {}'], ['Int', 0], ['Int', 5], ['Int', 12], ['Int', 12]],
    $returnsOf($proj, 'accumulator'),
    'generator_send: accumulator yields 0, 5, 12, then returns 12');
ct_assert_eq([['Int', 12]], $returnsOf($proj, 'drive_accumulator'),
    'generator_send: drive_accumulator returns 12');

// A resumption by send() carries the sent value as `<sent>` on its entry
// step -- the value the suspended `yield` expression evaluates to.  The
// first resumption, by current(), received nothing.
ct_assert_eq(
    [
        [15, []],
        [15, []],
        [18, [['total', ['Int', 0]], ['<sent>', ['Int', 5]]]],
        [18, [['total', ['Int', 5]], ['x', ['Int', 5]], ['<sent>', ['Int', 7]]]],
        [18, [['total', ['Int', 12]], ['x', ['Int', 7]], ['<sent>', ['Raw', 'null']]]],
    ],
    $entriesOf($proj, 'accumulator'),
    'generator_send: each send() resumption records the value it delivered');

ct_assert_eq(1, count($proj['Event'] ?? []), 'generator_send: 1 io event');
ct_assert_eq("acc=12\n", $proj['Event'][0]['content'] ?? null, 'generator_send: echo content');

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
echo "ALL E2E TESTS PASSED\n";

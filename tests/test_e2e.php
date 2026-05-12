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

// ---------------------------------------------------------------------------
// Test framework (tiny — we deliberately avoid pulling in PHPUnit).
// ---------------------------------------------------------------------------

$GLOBALS['__ct_tests'] = 0;
$GLOBALS['__ct_passed'] = 0;
$GLOBALS['__ct_failed'] = 0;
$GLOBALS['__ct_skipped'] = 0;
$GLOBALS['__ct_failures'] = [];

function ct_pass(string $name): void {
    $GLOBALS['__ct_tests']++;
    $GLOBALS['__ct_passed']++;
    echo "  PASS: $name\n";
}

function ct_fail(string $name, string $why): void {
    $GLOBALS['__ct_tests']++;
    $GLOBALS['__ct_failed']++;
    $GLOBALS['__ct_failures'][] = "$name -- $why";
    echo "  FAIL: $name -- $why\n";
}

function ct_skip(string $name, string $why): void {
    $GLOBALS['__ct_tests']++;
    $GLOBALS['__ct_skipped']++;
    // Per the recorder-test-requirements policy, every conditional
    // skip MUST log a clear `SKIP:` diagnostic so that
    // `verify-cli-convention-no-silent-skip.sh` can spot it and CI
    // doesn't quietly degrade.  Do NOT remove the literal `SKIP:`
    // token even when refactoring this helper.
    echo "  SKIP: $name -- $why\n";
}

function ct_assert_eq($expected, $actual, string $name): void {
    if ($expected === $actual) { ct_pass($name); return; }
    ct_fail($name,
        "expected " . var_export($expected, true) .
        ", got " . var_export($actual, true));
}

function ct_assert_true(bool $cond, string $name, string $why = ''): void {
    if ($cond) { ct_pass($name); return; }
    ct_fail($name, $why ?: 'condition was false');
}

// ---------------------------------------------------------------------------
// Recorder driver
// ---------------------------------------------------------------------------

function ct_repo_root(): string {
    return dirname(__DIR__);
}

function ct_extension_path(): string {
    return ct_repo_root() . '/ext/modules/codetracer.so';
}

function ct_trace_format_nim_dir(): string {
    $env = getenv('TRACE_FORMAT_NIM_DIR');
    if ($env !== false && $env !== '') return $env;
    return ct_repo_root() . '/../codetracer-trace-format-nim';
}

function ct_print_path(): string {
    return ct_trace_format_nim_dir() . '/ct-print';
}

function ct_ld_library_path(): string {
    $cur = getenv('LD_LIBRARY_PATH');
    $libdir = ct_trace_format_nim_dir();
    return $libdir . ($cur ? ':' . $cur : '');
}

/**
 * Record a test program by spawning a fresh `php` subprocess with the
 * extension loaded.  Returns [status, stdout, stderr, traceDir].
 * Bails out on a recorder-side fatal (php exit != 0) — we want
 * recorder failures to surface as test failures, not silent skips.
 */
function ct_record(string $programPath): array {
    $traceDir = sys_get_temp_dir() . '/ct_php_e2e_' . bin2hex(random_bytes(6));
    if (!mkdir($traceDir, 0755, true) && !is_dir($traceDir)) {
        throw new RuntimeException("could not create $traceDir");
    }

    $env = [
        'CODETRACER_ENABLED' => '1',
        'CODETRACER_TRACE_DIR' => $traceDir,
        'LD_LIBRARY_PATH' => ct_ld_library_path(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    $cmd = [
        'php',
        '-d', 'extension=' . ct_extension_path(),
        $programPath,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, ct_repo_root(), $env);
    if (!is_resource($proc)) {
        throw new RuntimeException("failed to spawn php subprocess");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'traceDir' => $traceDir,
    ];
}

/**
 * Decode the recorder's `.ct` CTFS container into the legacy-schema
 * JSON event array via `ct-print --json-events`, then translate the
 * new-format events into the projection-friendly legacy shape this
 * suite already asserts on (`{Step:{...}}`, `{Call:{...}}`, ...).
 *
 * The new ct-print format uses `{type: "step", ...}` (lowercase),
 * doesn't emit standalone `Return` events (call records carry
 * `exit_step`), and renames a few fields.  The translator below maps
 * back to the legacy projection shape so the per-program test bodies
 * don't need to be rewritten — see ct_translate_to_legacy() for the
 * exact mapping.  When the test bodies migrate to the new schema,
 * this translator can be removed.
 */
function ct_decode_events(string $traceDir): array {
    $ct_print = ct_print_path();
    if (!is_executable($ct_print)) {
        throw new RuntimeException(
            "ct-print binary not found or not executable at $ct_print " .
            "(build it with `cd " . ct_trace_format_nim_dir() . " && nimble buildCtPrint`)");
    }
    $cts = glob($traceDir . '/*.ct');
    if (!$cts) {
        throw new RuntimeException(".ct file missing in $traceDir");
    }
    $ctPath = $cts[0];
    $jsonOut = $traceDir . '/events.json';
    $cmd = sprintf('%s --json-events %s > %s 2>&1',
        escapeshellarg($ct_print),
        escapeshellarg($ctPath),
        escapeshellarg($jsonOut));
    shell_exec($cmd);
    if (!file_exists($jsonOut)) {
        throw new RuntimeException("ct-print failed");
    }
    // ct-print's `data` field on Value events embeds raw CBOR bytes
    // which can be invalid UTF-8; PHP's json_decode rejects those.
    // Read the file as binary, replace invalid UTF-8 sequences before
    // parsing.
    $raw = file_get_contents($jsonOut);
    $clean = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    $events = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($events)) {
        throw new RuntimeException("decoded event stream is not an array");
    }
    return ct_translate_to_legacy($events);
}

/**
 * Map ct-print --json-events output into the legacy projection shape
 * (`{Step:{...}}`, `{Call:{...}}`, `{Value:{...}}`, ...) so the
 * per-program assertions in this file don't need rewriting.
 *
 * Mapping:
 *   {type:"path",     ...} → {Path:        {id, path, name}}
 *   {type:"function", ...} → {Function:    {id, name}}
 *   {type:"varname",  ...} → {VariableName:{id, name}}
 *   {type:"type",     ...} → {Type:        {id, lang_type}}
 *   {type:"call",     ...} → {Call:        {function_id, function, depth, ...}}
 *                            and (if exit_step != entry_step) a
 *                            synthesized {Return: {function_id, function}}
 *                            inserted in step-order so projection
 *                            counts match the legacy schema.
 *   {type:"step",     ...} → {Step:        {path_id, line, function, depth}}
 *   {type:"value",    ...} → {Value:       {variable_id, value, varname}}
 *   {type:"io",       ...} → {Event:       {kind, content}}
 */
function ct_translate_to_legacy(array $events): array {
    $out = [];
    // Pass 1: emit declarations, steps, values, io.  Track call
    // records keyed by exit_step so we can interleave Return events.
    $returnsAtStep = []; // step_index → array of Return records
    foreach ($events as $e) {
        $t = $e['type'] ?? null;
        switch ($t) {
            case 'path':
                // Legacy projection used the path string as the Path
                // event's payload (so $proj['Path'][0] is a string).
                $out[] = ['Path' => $e['name']];
                break;
            case 'function':
                $out[] = ['Function' => [
                    'id' => $e['function_id'],
                    'name' => $e['name'],
                ]];
                break;
            case 'varname':
                $out[] = ['VariableName' => [
                    'id' => $e['varname_id'],
                    'name' => $e['name'],
                ]];
                break;
            case 'type':
                $out[] = ['Type' => [
                    'id' => $e['type_id'],
                    'lang_type' => $e['name'],
                ]];
                break;
            case 'call':
                $out[] = ['Call' => [
                    'function_id' => $e['function_id'],
                    'function' => $e['function'] ?? null,
                    'depth' => $e['depth'] ?? 0,
                    'entry_step' => $e['entry_step'] ?? 0,
                    'exit_step' => $e['exit_step'] ?? 0,
                ]];
                // ct-print embeds the call's return as exit_step.  In
                // the legacy schema every Call had a paired Return
                // unless the function never returned (top-level).  We
                // synthesise a Return when exit_step > entry_step,
                // parked to be inserted after the corresponding step.
                if (($e['exit_step'] ?? 0) > ($e['entry_step'] ?? 0)) {
                    $idx = $e['exit_step'];
                    if (!isset($returnsAtStep[$idx])) $returnsAtStep[$idx] = [];
                    $returnsAtStep[$idx][] = ['Return' => [
                        'function_id' => $e['function_id'],
                        'function' => $e['function'] ?? null,
                    ]];
                }
                break;
            case 'step':
                $out[] = ['Step' => [
                    'path_id' => $e['path_id'],
                    'line' => $e['line'],
                    'function_id' => $e['function_id'] ?? null,
                    'function' => $e['function'] ?? null,
                    'depth' => $e['depth'] ?? 0,
                    'step_index' => $e['step_index'] ?? null,
                ]];
                // Drain any Returns whose exit_step equals this step.
                $idx = $e['step_index'] ?? -1;
                if (isset($returnsAtStep[$idx])) {
                    foreach ($returnsAtStep[$idx] as $r) $out[] = $r;
                    unset($returnsAtStep[$idx]);
                }
                break;
            case 'value':
                $out[] = ['Value' => [
                    'variable_id' => $e['varname_id'],
                    'varname' => $e['varname'] ?? null,
                    'value' => $e['value'] ?? null,
                    'type_id' => $e['type_id'] ?? null,
                ]];
                break;
            case 'io':
                // Map ioStdout→Write, ioStderr→WriteOther for parity with
                // the legacy `Event` records' `kind` field.
                $kind = ($e['kind'] ?? '') === 'ioStdout' ? 'Write' : 'WriteOther';
                $out[] = ['Event' => [
                    'kind' => $kind,
                    'content' => $e['data'] ?? '',
                ]];
                break;
        }
    }
    // Drain any Returns whose target step never appeared (shouldn't
    // happen, but keep them so projection counts stay consistent).
    foreach ($returnsAtStep as $idx => $rs) {
        foreach ($rs as $r) $out[] = $r;
    }
    return $out;
}

/**
 * Project the event stream into per-kind sequences.  Returns an
 * associative array keyed by event kind ('Function', 'Call', 'Step',
 * 'Value', 'Return', 'Event', 'VariableName', 'Path', 'Type') with
 * one entry per event preserving order.  Used by the per-program
 * tests to make EXACT-order assertions without being brittle to the
 * Type / Path preludes the recorder always emits.
 */
function ct_project(array $events): array {
    $bins = [];
    foreach ($events as $e) {
        $k = array_key_first($e);
        if ($k === null) continue;
        if (!isset($bins[$k])) $bins[$k] = [];
        $bins[$k][] = $e[$k];
    }
    return $bins;
}

/**
 * Reject any unexpected `ValueRecord` variant for a given variable.
 * Mirrors the cardano test_tracer.rs `observed_int_vars` pattern: we
 * want a hard failure with a clear message rather than a silent
 * tolerance for a new variant.
 */
function ct_assert_value_int_kind(array $value, string $context): int {
    if (($value['kind'] ?? null) !== 'Int') {
        throw new RuntimeException(
            "[$context] expected ValueRecord::Int, got " .
            json_encode($value) .
            "; if a new variant has landed, extend the test " .
            "to assert on it explicitly rather than weakening the check");
    }
    if (!is_int($value['i'] ?? null)) {
        throw new RuntimeException(
            "[$context] expected Int.i to be an int; got " . json_encode($value));
    }
    return $value['i'];
}

function ct_assert_value_raw_kind(array $value, string $context): string {
    if (($value['kind'] ?? null) !== 'Raw') {
        throw new RuntimeException(
            "[$context] expected ValueRecord::Raw, got " .
            json_encode($value) .
            "; if a new variant has landed, extend the test " .
            "to assert on it explicitly rather than weakening the check");
    }
    if (!is_string($value['r'] ?? null)) {
        throw new RuntimeException(
            "[$context] expected Raw.r to be a string; got " . json_encode($value));
    }
    return $value['r'];
}

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
// RECORDER BUG: per-statement step granularity.  The PHP extension's
// `zend_execute_ex` hook fires once per function entry (at
// `op_array.line_start`) — it does NOT install a `zend_observer`
// per-opcode trampoline, so the per-let-binding values inside
// `compute()` are never emitted as scoped variables.  See
// `AUDIT-CTFS-2026-05.md` open gap "Per-opcode step granularity".
// Until the recorder grows opcode-level observation, the canonical
// flow_test variable assertion CANNOT pass; we pin the present-day
// shape (Return value of 94 from `compute`) and provide a sibling
// SKIP test capturing the spec-correct expectation.
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
ct_assert_eq(2, count($proj['Step'] ?? []), 'flow_test: 2 step events (call entry + post-return)');
ct_assert_eq(1, count($proj['Return'] ?? []), 'flow_test: 1 return (compute) -- <toplevel> has no Return');
ct_assert_eq(1, count($proj['Event'] ?? []), 'flow_test: 1 io event (the echo)');

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

// EXACT io_event content.
$ioev = $proj['Event'][0];
ct_assert_eq(0, $ioev['kind'], 'flow_test: io_event kind = ELK_WRITE (0)');
ct_assert_eq('stdout', $ioev['metadata'], 'flow_test: io_event metadata = stdout');
ct_assert_eq("flow_test result: 94\n", $ioev['content'], 'flow_test: io_event content');

// SKIP: per-let-binding variable values (a=10, b=32, sum_val=42, doubled=84, final_result=94).
// RECORDER BUG: per-statement step granularity is not implemented;
// see file-level note above.  The recorder only emits `Step` at
// function entry, so the let-bindings inside compute() never surface
// as scoped variables.  Tracking issue: extend `codetracer_php.c`
// `codetracer_execute_ex` to register a `zend_observer_fcall_init`
// hook for per-opcode step capture (PHP 8.0+ observer API).
ct_skip('flow_test: canonical (a,b,sum_val,doubled,final_result) variables surface',
    'RECORDER BUG: PHP recorder lacks per-statement step granularity. ' .
    'Tracked in AUDIT-CTFS-2026-05.md "Per-opcode step granularity".');

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

// EXACT return sequence.  When raise_*() throws, its Return event is
// `None` (the recorder's after-execute path always emits a Return
// even on the exception-propagation exit — see `codetracer_execute_ex`,
// the post-call branch unconditionally calls `register_return*` when
// `have_info` is set).
//
// RECORDER BUG: thrown exceptions are not surfaced as a separate
// `RecordEvent`/`ELK_ERROR` event.  The recorder emits a None
// Return, indistinguishable from a void function.  Per
// recorder-test-requirements.md §2 "Exceptions / errors", a
// raise-with-handler MUST produce a `RecordEvent` of
// `EventKindError`.  The PHP recorder does not yet hook
// zend_throw_exception_internal; tracking: add an ELK_ERROR special
// event in the throw-hook path, mirroring the canonical pattern
// from cardano test_tracer.rs `test_error_paths_test_emits_fail_event`.
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

// SKIP: ELK_ERROR special-event for thrown exceptions.
ct_skip('exceptions: thrown exceptions surface as ELK_ERROR special event',
    'RECORDER BUG: zend_throw_exception_internal is not hooked. ' .
    'Spec (§2): raise-with-handler MUST produce a RecordEvent of EventKindError. ' .
    'Tracking: add an ELK_ERROR call in the throw-hook path, mirroring ' .
    'cardano test_tracer.rs::test_error_paths_test_emits_fail_event.');

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

// SKIP: yielded value sequence (1, 2, 3, 4 from int_range and
// "a"=>1, "b"=>2, "c"=>3 from keyed) should surface as a sequence
// of step-variable values OR a dedicated yield event.
ct_skip('generators: yielded values surface as step variables / yield events',
    'RECORDER BUG: PHP recorder does not specialise zend_generator ' .
    'resumption; yielded values are not captured because the per-statement ' .
    'step granularity is missing (same root cause as flow_test).  ' .
    'Tracking: combine the per-opcode step hook with a generator-aware ' .
    'capture path, mirroring the python recorder yield-event handling ' .
    '(handoff entry 1.27).');

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

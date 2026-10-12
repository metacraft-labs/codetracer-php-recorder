<?php
/**
 * Shared harness for the recorder suites: a tiny assertion framework, the
 * recorder driver (a real `php -d extension=...` subprocess), and the decoder
 * that turns a `.ct` container into the legacy projection through `ct-print
 * --full`.  Used by test_e2e.php and test_recorder_semantics.php.
 *
 * No mocks: every assertion is made against a container the real extension
 * wrote through the real trace writer, decoded by the canonical reader.
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
    return dirname(__DIR__, 2);
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
function ct_record(string $programPath, array $programArgs = []): array {
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
        ...$programArgs,
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
    // `--full` decodes call args, return values, and IO bytes —
    // unlike `--json-events`, which emits a flat event stream
    // without folding return values into the call records.  The
    // policy mandates exact-decoded-value assertions, so we need
    // `--full`.
    $cmd = sprintf('%s --full %s > %s 2>&1',
        escapeshellarg($ct_print),
        escapeshellarg($ctPath),
        escapeshellarg($jsonOut));
    shell_exec($cmd);
    if (!file_exists($jsonOut)) {
        throw new RuntimeException("ct-print failed");
    }
    $raw = file_get_contents($jsonOut);
    $clean = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    $doc = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($doc) || !isset($doc['events'])) {
        throw new RuntimeException("decoded document missing events[]");
    }
    return ct_translate_to_legacy($doc);
}

/**
 * EventLogKind name -> ordinal, as `codetracer-trace-format-spec`
 * `trace-events.md` §"EventLogKind (u8 enum)" assigns them.
 */
const CT_EVENT_LOG_KINDS = [
    'Write' => 0,
    'WriteFile' => 1,
    'WriteOther' => 2,
    'Read' => 3,
    'ReadFile' => 4,
    'ReadOther' => 5,
    'ReadDir' => 6,
    'OpenDir' => 7,
    'CloseDir' => 8,
    'Socket' => 9,
    'Open' => 10,
    'Error' => 11,
    'TraceLogEvent' => 12,
    'EvmEvent' => 13,
];

/**
 * Map a ct-print --full document into the legacy projection shape
 * (`{Step:{...}}`, `{Call:{...}}`, `{Value:{...}}`, ...) so the
 * per-program assertions in this file don't need rewriting.
 *
 * `--full` gives us:
 *   * top-level paths[], functions[], varnames[], types[] arrays
 *     (interning tables) — translated to the legacy declaration
 *     events.
 *   * events[] with `kind` discriminator: call_entry, call_exit,
 *     step, value, io.  call_exit carries the decoded
 *     return_value, so we map it to the legacy Return event.
 *
 * The synthetic `<toplevel>` frame (depth==0, name=="<toplevel>")
 * gets a Call but no Return, mirroring the legacy convention that
 * the outermost frame never emitted a standalone Return event.
 */
function ct_translate_to_legacy(array $doc): array {
    $out = [];
    $types = $doc['types'] ?? [];
    foreach (($doc['paths'] ?? []) as $i => $name) {
        $out[] = ['Path' => $name];
    }
    foreach (($doc['functions'] ?? []) as $i => $name) {
        $out[] = ['Function' => ['id' => $i, 'name' => $name]];
    }
    foreach (($doc['varnames'] ?? []) as $i => $name) {
        $out[] = ['VariableName' => ['id' => $i, 'name' => $name]];
    }
    foreach (($doc['types'] ?? []) as $i => $name) {
        $out[] = ['Type' => ['id' => $i, 'lang_type' => $name]];
    }
    foreach (($doc['events'] ?? []) as $e) {
        $k = $e['kind'] ?? null;
        switch ($k) {
            case 'call_entry':
                $out[] = ['Call' => [
                    'call_key' => $e['call_key'] ?? null,
                    'function_id' => $e['function_id'],
                    'function' => $e['function'] ?? null,
                    'depth' => $e['depth'] ?? 0,
                    'entry_step' => $e['entry_step'] ?? 0,
                    'exit_step' => $e['exit_step'] ?? 0,
                ]];
                break;
            case 'call_exit':
                // Synthetic <toplevel> never emits a Return event —
                // matches the legacy projection convention.
                $isToplevel = ($e['depth'] ?? 0) === 0
                    && (($e['function'] ?? '') === '<toplevel>');
                if (!$isToplevel) {
                    // ct-print --full emits {kind:"Void"} for
                    // implicit-return frames (constructors, throw-
                    // exit paths, register_return-with-no-value).
                    // The legacy projection used {kind:"None"} for
                    // both — map Void → None to keep parity.
                    $rv = $e['return_value'] ?? ['kind' => 'None'];
                    if (($rv['kind'] ?? '') === 'Void') {
                        $rv = ['kind' => 'None'];
                    }
                    $out[] = ['Return' => [
                        'call_key' => $e['call_key'] ?? null,
                        'function_id' => $e['function_id'],
                        'function' => $e['function'] ?? null,
                        'return_value' => $rv,
                        // The value a call that exits by an exception
                        // carries in its record (`trace-events.md`
                        // §"Call Stream"); null when it returned.
                        'exception' => $e['exception'] ?? null,
                    ]];
                }
                break;
            case 'step':
                // Raise and Catch are exec records but not steps
                // (`trace-events.md` §"Which exec records are steps"), so
                // they get their own projection kinds and never count as
                // a Step.  They carry no position of their own: the
                // reader resolves them to the running position, i.e. the
                // step before them, and to the innermost open call.
                $stepKind = $e['step_kind'] ?? 'sekAbsoluteStep';
                if ($stepKind === 'sekRaise') {
                    $out[] = ['Raise' => [
                        'type' => $types[$e['exception_type_id']] ?? null,
                        'message' => $e['exception_message'] ?? null,
                        'function' => $e['function'] ?? null,
                        'line' => $e['line'] ?? null,
                        'step_index' => $e['step_index'] ?? null,
                    ]];
                    break;
                }
                if ($stepKind === 'sekCatch') {
                    $out[] = ['Catch' => [
                        'type' => $types[$e['catch_exception_type_id']] ?? null,
                        'function' => $e['function'] ?? null,
                        'step_index' => $e['step_index'] ?? null,
                    ]];
                    break;
                }
                if ($stepKind !== 'sekAbsoluteStep' && $stepKind !== 'sekDeltaStep') {
                    throw new RuntimeException(
                        'unexpected exec record kind ' . json_encode($stepKind) .
                        '; extend the projection rather than folding it into Step');
                }
                $vars = [];
                foreach (($e['vars'] ?? []) as $v) {
                    $vars[] = ['name' => $v['varname'] ?? null, 'value' => $v['value'] ?? null];
                }
                $out[] = ['Step' => [
                    // Every value registered at this step, in registration
                    // order, as [name, decoded ValueRecord] pairs.
                    'vars' => $vars,
                    'path_id' => $e['path_id'] ?? null,
                    'line' => $e['line'] ?? null,
                    'function_id' => $e['function_id'] ?? null,
                    'function' => $e['function'] ?? null,
                    'depth' => $e['depth'] ?? 0,
                    'step_index' => $e['step_index'] ?? null,
                ]];
                break;
            case 'value':
                $out[] = ['Value' => [
                    'variable_id' => $e['varname_id'] ?? null,
                    'varname' => $e['varname'] ?? null,
                    'value' => $e['value'] ?? null,
                    'type_id' => $e['type_id'] ?? null,
                ]];
                break;
            case 'io':
                // `io_kind` is the EventLogKind name as `trace-events.md`
                // §"EventLogKind (u8 enum)" spells it; the projection
                // keeps the numeric ordinal that table assigns.
                // `metadata` is the recorder's own stream label, stored
                // verbatim in the container.
                $ioKind = $e['io_kind'] ?? null;
                if (!array_key_exists($ioKind, CT_EVENT_LOG_KINDS)) {
                    throw new RuntimeException(
                        'io event has an io_kind that is not an EventLogKind: ' .
                        json_encode($ioKind));
                }
                $out[] = ['Event' => [
                    'step_id' => $e['step_id'] ?? null,
                    'kind' => CT_EVENT_LOG_KINDS[$ioKind],
                    // ct-print leaves `metadata` out when the slot is
                    // empty, so an absent key IS the empty slot.
                    'metadata' => $e['metadata'] ?? '',
                    'content' => $e['text'] ?? '',
                ]];
                break;
        }
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


/**
 * A decoded ValueRecord reduced to [variant, payload] so a test can compare
 * a whole sequence of values exactly, variant included.  An unknown variant
 * is a hard error rather than a tolerated quirk.
 */
function ct_value_brief(?array $value): array {
    $kind = $value['kind'] ?? null;
    switch ($kind) {
        case 'Int':    return ['Int', $value['i']];
        case 'Raw':    return ['Raw', $value['r']];
        case 'String': return ['String', $value['text']];
        case 'Error':  return ['Error', $value['msg']];
        case 'None':   return ['None', null];
        case 'Void':   return ['Void', null];
    }
    throw new RuntimeException('ct_value_brief: unexpected ValueRecord ' .
        json_encode($value) . '; extend the helper rather than weakening the check');
}

/** A step's values as an ordered list of [name, [variant, payload]]. */
function ct_step_vars(array $step): array {
    return array_map(fn($v) => [$v['name'], ct_value_brief($v['value'])], $step['vars']);
}

/** Every step as "function:line", in recording order. */
function ct_step_positions(array $proj): array {
    return array_map(fn($s) => $s['function'] . ':' . $s['line'], $proj['Step'] ?? []);
}

/** The Step record with the given exec index; throws when there is none. */
function ct_step_at(array $proj, int $stepIndex): array {
    foreach (($proj['Step'] ?? []) as $s) {
        if ($s['step_index'] === $stepIndex) return $s;
    }
    throw new RuntimeException("no step record at exec index $stepIndex");
}

/** The exception type name a decoded exception value names, via its type id. */
function ct_type_name(array $doc, ?array $value): ?string {
    if ($value === null || !isset($value['type_id'])) return null;
    return $doc['types'][$value['type_id']] ?? null;
}

/** ct_decode_events() plus the raw ct-print document, for the type table. */
function ct_decode_with_doc(string $traceDir): array {
    $events = ct_decode_events($traceDir);
    $doc = json_decode(
        mb_convert_encoding(file_get_contents($traceDir . '/events.json'), 'UTF-8', 'UTF-8'),
        true, 512, JSON_THROW_ON_ERROR);
    return [$events, $doc];
}

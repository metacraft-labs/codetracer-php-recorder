# PHP Recorder CTFS Audit — 2026-05-02

This audit checks the `codetracer-php-recorder` (a Zend extension at
`ext/codetracer_php.c`) against the canonical CodeTracer multi-stream
CTFS schema and the section 5.6 audit checklist in
`/tmp/isonim-migration.txt`. The previously-audited Ruby (1.21, 1.22,
1.30), Python (1.27), JavaScript (1.38) and EVM (1.39) recorders set
the canonical fix patterns.

The PHP recorder is the seventh recorder audited and the first
audited recorder that goes through the C FFI (`libcodetracer_trace_
writer_ffi.so`) rather than the in-process Rust crate
(`codetracer_trace_writer_nim` / `codetracer_trace_writer`). This
brings up a new cross-cutting issue documented at the end of this
file: the C FFI does not currently expose the `Ctfs` writer variant
or the `register_thread_*` / `register_call_arg` entry points.

## Summary

| #   | Check                                                    | Status                     | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| --- | -------------------------------------------------------- | -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| a   | `register_call` for each call                            | OK (post-fix)              | `codetracer_execute_ex` resolves the function ID via `trace_writer_ensure_function_id` and emits `trace_writer_register_call` for every entered user function (after staging args — see (b)). Internal calls and re-entry are protected by the `in_trace_hook` re-entrancy guard.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| b   | Call args via `register_call_arg` / `arg`                | PARTIAL — fixed in spirit  | Args are now staged via `trace_writer_register_variable_raw` / `_int` BEFORE `trace_writer_register_call` (canonical Ruby/JS pattern from 1.22 / 1.38). However the C FFI (`codetracer_trace_writer_ffi`) does NOT expose a dedicated `register_call_arg` entry point — only `register_variable_*`. Until the FFI grows that entry point, args surface as scoped variables at the function-entry step rather than on `CallRecord.args` directly. This is a **cross-cutting FFI gap** (see "New issues uncovered" below); the PHP-side ordering is canonical so when the FFI gains `register_call_arg`, only the call-site replacement is needed.                                                                                                                                                                                                                                                                                  |
| c   | Write/WriteOther via `register_special_event` for stdout | OK (post-fix)              | New SAPI `ub_write` hook in `codetracer_sapi_ub_write` interposes on PHP's stdout-bound output (`echo`, `print`, `var_dump`, `printf`, raw HTML body output) and routes every chunk through `trace_writer_register_special_event(ELK_WRITE, "stdout", content)`. This mirrors the canonical IO-capture pattern from handoff entry 1.27 (Python). The hook is installed in `PHP_RINIT` (after `tracing_enabled = 1` so the very first `echo` is captured) and restored in `PHP_RSHUTDOWN`. An `in_io_hook` re-entry guard prevents accidental recursion.                                                                                                                                                                                                                                                                                                                                                                           |
| c.2 | Write/WriteOther for stderr                              | **OPEN GAP**               | PHP `error_log`, `fwrite(STDERR, ...)` and `php://stderr` writes do NOT route through `sapi_module.ub_write`. Capturing them requires either a `php_log_err_with_severity` hook (for `error_log`) or hooks on the stream-wrapper layer. Not blocking for stdout-only programs, tracked as a follow-up.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| c.3 | Read/ReadFile capture                                    | **OPEN GAP**               | `fread`, `file_get_contents`, `fopen` reads are not captured. Same architectural status as JS recorder (1.38 documented "JS recorder does not capture stdin / fs reads"). Out of scope for this audit.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| d   | Thread events (ThreadStart / Exit / Switch)              | N/A                        | PHP's request model is single-threaded per request (the `pthreads` / `parallel` extensions are not in scope). The recorder correctly emits no thread events. Note: even if PHP gained worker threads, the C FFI does not currently expose `register_thread_*` (cross-cutting FFI gap, see below).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| e   | Step records for line navigation                         | OK                         | `trace_writer_register_step(file, line)` is called in `codetracer_execute_ex` immediately after `register_call` for every entered user function. Line granularity is the function-entry line (`op_array.line_start`); per-statement step granularity would require a `zend_observer` hook on every executed opcode and is tracked as a follow-up.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| f   | Canonical CTFS schema match                              | **OPEN GAP — FFI blocker** | The recorder calls `trace_writer_new(script, FMT_BINARY)`. In the FFI's `Fmt` enum, `FMT_BINARY` (= 2) maps to `TraceEventsFileFormat::Binary`, i.e. the **legacy CBOR+Zstd container**, NOT the canonical `Ctfs` multi-stream container. This is the same cross-cutting issue diagnosed in EVM 1.39 — but unlike EVM (which uses `codetracer_trace_writer_nim` directly and could switch to `TraceEventsFileFormat::Ctfs` in one line), the PHP extension goes through `codetracer_trace_writer_ffi` whose `Fmt` enum does not expose a `Ctfs` variant at all (see `codetracer-trace-format/codetracer_trace_writer_ffi/codetracer_trace_writer.h`: only `FMT_JSON`, `FMT_BINARY_V0`, `FMT_BINARY`). Fixing this requires extending the C FFI to add `FMT_CTFS` (and have `to_format` map it to `TraceEventsFileFormat::Ctfs`), then changing the PHP extension to pass `FMT_CTFS`. Tracked in handoff entry 1.41 / section 5.6. |
| g   | Obsolete `#[no_mangle]` stubs                            | OK                         | The PHP recorder is a pure C Zend extension — no Rust crate, no `#[no_mangle]` stubs. This recorder cannot collide with upstream Nim exports the way the JS recorder did pre-1.38.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

## Concrete fixes applied (commit landing with this audit)

### 1. SAPI `ub_write` hook for stdout capture (canonical IO pattern)

PHP routes every stdout-bound write — `echo`, `print`, `var_dump`,
`printf`, raw HTML body output — through `sapi_module.ub_write`.
The new `codetracer_sapi_ub_write` interposes on this entry point
and emits a `register_special_event(ELK_WRITE, "stdout", content)`
for each chunk. This mirrors the canonical IO-capture pattern
established for the Python recorder in handoff entry 1.27
(`register_special_event` with an EventLogKind matching the
destination FD).

The hook is installed in `PHP_RINIT` AFTER `tracing_enabled = 1` so
the very first `echo` is captured, and restored in `PHP_RSHUTDOWN`
BEFORE the writer teardown so any cleanup writes during teardown
go to the original handler. An `in_io_hook` re-entry guard
prevents accidental recursion if the trace writer (or downstream
code) ever calls back into a print path.

### 2. Call-arg staging reordered to BEFORE `register_call`

Per the canonical CTFS recorder contract (see `register_call`'s doc
comment in `codetracer_trace_writer.h`: _"For simplicity the FFI
does not expose argument passing — call
`trace_writer_register_variable_with_full_value` for each arg
before this function."_), arguments must be staged via the
variable-emission entry points BEFORE `register_call` so the call
record decoder sees them at function-entry time.

Pre-fix, `codetracer_execute_ex` emitted `register_call` and
`register_step` first, then walked the parameter list and called
`serialize_zval` for each arg. This ordered the args AFTER the
call record, so they appeared at the next step's frame instead of
on the call's entry frame. Same class of bug as the Ruby (1.22)
and JS (1.38) recorders.

The fix splits the existing `if (func_name && file_name)` block
into three explicit phases:

1. Resolve `function_id` via `trace_writer_ensure_function_id`.
2. Stage each arg via `serialize_zval` (which dispatches to
   `trace_writer_register_variable_int` / `_raw`).
3. Emit `register_call(fid)` and `register_step(file, line)`.

Note: as documented in (b) above, the C FFI does not expose
`register_call_arg`, so the staged args still surface as scoped
variables at the function-entry step rather than on
`CallRecord.args` directly. The ordering change is necessary but
not sufficient; the cross-cutting FFI gap is what currently blocks
populating `CallRecord.args` end-to-end.

## Build and smoke-test verification

The extension builds cleanly under the codetracer dev shell:

```
direnv exec /home/zahary/metacraft/codetracer-php-recorder \
    bash -c "cd ext && make"
```

Output: `ext/modules/codetracer.so` (no warnings, no errors).

End-to-end smoke test under `nix-shell -p php84`:

```
LD_LIBRARY_PATH=/home/zahary/metacraft/codetracer-trace-format/target/release \
CODETRACER_ENABLED=1 \
CODETRACER_TRACE_DIR=/tmp/codetracer_php_audit/trace \
nix-shell -p php84 --run "php \
    -d extension=ext/modules/codetracer.so \
    /tmp/codetracer_php_audit/sample.php"
```

For a sample program that defines `greet($name)`, calls `echo` and
returns `strlen($name)`, the recorder produces:

- `trace.bin` (389 B)
- `trace_metadata.json` (`workdir`, `program`, `args`)
- `trace_paths.json`

The `phpinfo()` output shows `CodeTracer support => enabled`
confirming the extension loads.

A future audit smoke test should assert against the produced trace
that:

- The Call record for `greet` exists with the resolved function ID.
- The Variable records for the `$name` arg appear at the call's
  entry step (current behaviour pending FFI `register_call_arg`).
- At least one Special Event with `kind=ELK_WRITE` and
  `metadata="stdout"` is emitted for the `echo` output.
- At least one Step record points at the user file.

This requires either (a) a Rust-side test that opens the produced
`trace.bin` via `codetracer_trace_reader::create_trace_reader(
TraceEventsFileFormat::Binary)` and walks the events, or
(b) a switch to a CTFS-format output (blocked on the FFI gap in
(f)) so the canonical `NimTraceReaderHandle` can index it. Either
path is tracked as a follow-up.

## Open gaps / follow-ups

### Cross-cutting C FFI gaps (block multiple checklist items)

The C FFI `codetracer_trace_writer_ffi` is the SOLE binding the PHP
extension can use (it cannot link against the Rust `codetracer_
trace_writer` crate or the `codetracer_trace_writer_nim` Nim-backed
crate the way Rust-language recorders can). The FFI is missing:

- **`FMT_CTFS` enum value + writer construction.** Without this,
  PHP traces use the legacy CBOR+Zstd `Binary` format which the
  canonical `NimTraceReaderHandle` and the db-backend
  `CTFSTraceReader` cannot consume directly without a postprocess
  pass. This is the same root cause as EVM 1.39's `Json` issue,
  surfacing differently because the FFI doesn't even offer Ctfs as
  an option.
- **`register_call_arg(name, value)`.** The PHP recorder stages
  args via `register_variable_*` because there's no dedicated
  call-arg entry point on the FFI. This makes args appear as
  scoped variables instead of on `CallRecord.args`.
- **`register_thread_start` / `register_thread_exit` /
  `register_thread_switch`.** Trivially N/A for current PHP
  (single-threaded request model) but blocks future support for
  `pthreads` / `parallel` PHP extensions.

Concrete fix shape (out of scope for this PHP audit; tracked in
section 5.6 of the migration handoff):

1. In `codetracer-trace-format/codetracer_trace_writer_ffi/src/lib.rs`,
   add `Ctfs = 3` to the `FfiTraceFormat` enum and extend
   `to_format` to map it to `TraceEventsFileFormat::Ctfs`.
2. Add `trace_writer_register_call_arg(handle, name, value_repr,
type_kind, type_name)` mirroring the Nim writer's `arg(name,
value)` API.
3. Add `trace_writer_register_thread_{start,exit,switch}` mirroring
   the post-1.30 dedicated entry points on `NimTraceWriter`.
4. Re-run `cbindgen` so the regenerated header reaches every
   FFI-using recorder (PHP, plus any future C/C++ Zend / native
   recorders).

Once that lands, the PHP-side change is a one-line swap of
`FMT_BINARY` → `FMT_CTFS` and replacing the `serialize_zval`
call inside the call-arg staging block with a direct
`trace_writer_register_call_arg` call. No re-architecting needed
on the recorder side because the call-arg ordering fix in this
audit already places the args BEFORE `register_call`.

### Stderr capture (`c.2`)

PHP `error_log`, `fwrite(STDERR, ...)` and direct writes to
`php://stderr` don't go through `sapi_module.ub_write`. Two
workable hook points:

- `php_log_err_with_severity` for `error_log` — but this is a
  static helper and may not be replaceable across PHP versions.
- The stream-wrapper layer for `php://stderr`. Hooking on
  `php_stream_write` would catch all `fwrite` and `print` against
  any stream, but that's a much wider net than just stderr.

Pragmatic shape: gate on the destination stream's URL, emit
`register_special_event(ELK_WRITE_OTHER, "stderr", content)`
mirroring the JS recorder's `WriteOther = 2` choice from 1.38.

### Read capture (`c.3`)

`fread`, `file_get_contents`, `fopen`-then-read are not captured.
Same architectural status as JS recorder (1.38). Hooking
`php_stream_read` would catch these but matches the breadth concern
above. Out of scope for this audit.

### Per-opcode step granularity

`register_step` currently fires once per user function entry, at
`op_array.line_start`. For a per-statement step record (so the
GUI's flow renderer can navigate inside multi-statement function
bodies) the recorder would need a `zend_observer` hook installed
via the PHP 8.0+ observer API, or per-opcode trampoline via
`zend_user_opcode_handlers`. The function-entry-only granularity
is what the e2e PHP fixtures exercise today; multi-statement
navigation is a quality-of-life follow-up.

### Return value capture for non-user functions

Internal (built-in) functions don't have a `ZEND_USER_FUNCTION`
opcode trace, so `func->type != ZEND_USER_FUNCTION` skips
arg/return capture. This means built-in calls like `strlen($s)`
appear in the call-graph (their entry is logged at the user-side
caller's step) but their return value isn't surfaced. Tracked as
a low-priority follow-up.

### Smoke test asserting against produced trace

This audit verifies the recorder runs and produces non-empty trace
files. A proper regression-pinning smoke test (analogous to
EVM's `test_ctfs_audit.rs` and JS's `tests/e2e/ctfs-audit.test.ts`)
needs a reader-side harness. Two paths:

- Add a Rust integration crate to this repo (`tests/audit/`) that
  shells out `php -d extension=...` then opens the produced
  `trace.bin` via `codetracer_trace_reader` and asserts on Call /
  Variable / Step / Event / SpecialEvent records.
- Or wait for the FFI to gain `Ctfs`, then rely on the already
  audited `NimTraceReaderHandle` path.

Out of scope for this audit's commit but tracked as the
highest-priority follow-up before a 1.42 PHP audit.

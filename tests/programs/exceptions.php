<?php
/**
 * Exceptions coverage: try/catch/finally, multiple types, re-throw.
 *
 * Per recorder-test-requirements.md §2 universal-checklist row
 * "Exceptions / errors" requires:
 *   - raise/throw/panic with handler
 *   - raise without handler (program-terminating)
 *
 * PHP equivalent: `throw new Exception(...)` caught by `catch`, plus
 * an unhandled throw at program end.  We wrap the unhandled throw in
 * a separate function (`will_terminate`) and DO NOT call it from this
 * program — the test_e2e.php harness invokes it via a separate php
 * subprocess and asserts on the non-zero exit + stderr.  That keeps
 * the main trace clean while still covering both branches.
 */

class AppError extends \RuntimeException {}
class NetworkError extends AppError {}
class FormatError extends \RuntimeException {}

function raise_app(): void { throw new AppError("app-failure"); }
function raise_net(): void { throw new NetworkError("net-failure"); }
function raise_fmt(): void { throw new FormatError("fmt-failure"); }

function classify_error(callable $fn): string {
    try {
        $fn();
        return "ok";
    } catch (NetworkError $e) {
        return "net:" . $e->getMessage();
    } catch (AppError $e) {
        return "app:" . $e->getMessage();
    } catch (FormatError $e) {
        return "fmt:" . $e->getMessage();
    }
}

function with_finally(): string {
    $marker = "before";
    try {
        try {
            raise_app();
        } catch (AppError $e) {
            // Re-throw to exercise that path.
            throw $e;
        } finally {
            $marker = "finally1";
        }
    } catch (AppError $e) {
        return $marker . "->caught:" . $e->getMessage();
    }
    return $marker . "->never";
}

$results = [
    'app' => classify_error('raise_app'),
    'net' => classify_error('raise_net'),
    'fmt' => classify_error('raise_fmt'),
    'fin' => with_finally(),
];
foreach ($results as $k => $v) {
    echo "$k=$v\n";
}

// `will_terminate` is intentionally NOT called here — see file-level
// docblock.  Callers that want the unhandled-throw branch can invoke
// the program with `--terminate` and the script below will throw.
function will_terminate(): void {
    throw new \RuntimeException("unhandled");
}

if (in_array('--terminate', $argv ?? [], true)) {
    will_terminate();
}

<?php
/**
 * RS-M7 — PHP web-request span emission.
 *
 * Four integration tests over REAL servers recording a REAL application:
 *
 *   php_builtin_server_requests_land_in_one_container
 *   php_fpm_multi_worker_requests_all_appear
 *   php_fpm_default_control_timeout_still_writes_every_container
 *   php_interning_shared_across_requests
 *
 * MOCKS: none.  Every test starts an actual `php -S` process or an actual
 * php-fpm pool with the extension loaded, drives it over a real loopback
 * socket, and reads the resulting `.ct` containers back.  Spans are decoded
 * through `ct_spans_json()` — the canonical Nim span-stream reader that
 * `ct print -f http` uses — rather than a decoder written for the tests, so a
 * test can never agree with a writer bug and report success for bytes no
 * consumer can read.  Interning tables come from `ct-print --full`, the
 * shipped CLI.
 *
 * Run:
 *   php -d extension=ext/modules/codetracer.so tests/test_request_spans.php
 *
 * The extension must be loaded in THIS process too, because
 * `codetracer_spans_json()` is how the spans are read back.  CODETRACER_ENABLED
 * is deliberately NOT set here: the harness passes it only to the server
 * processes it spawns, so the test runner itself is not recorded.
 */

declare(strict_types=1);

require_once __DIR__ . '/programs/web/session_driver.php';

// ---------------------------------------------------------------------------
// Tiny assertion harness (same shape as tests/test_e2e.php)
// ---------------------------------------------------------------------------

$ctTests = 0;
$ctPassed = 0;
$ctFailed = 0;

function ct_pass(string $name): void
{
    global $ctTests, $ctPassed;
    $ctTests++;
    $ctPassed++;
    echo "  PASS: $name\n";
}

function ct_fail(string $name, string $why): void
{
    global $ctTests, $ctFailed;
    $ctTests++;
    $ctFailed++;
    echo "  FAIL: $name\n        $why\n";
}

function ct_check(bool $cond, string $name, string $why = ''): void
{
    if ($cond) {
        ct_pass($name);
        return;
    }
    ct_fail($name, $why !== '' ? $why : 'condition was false');
}

function ct_eq($expected, $actual, string $name): void
{
    if ($expected === $actual) {
        ct_pass($name);
        return;
    }
    ct_fail($name, 'expected ' . var_export($expected, true) .
        ', got ' . var_export($actual, true));
}

function ct_tempdir(string $tag): string
{
    $dir = sys_get_temp_dir() . '/ct_php_' . $tag . '_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create $dir");
    }
    return $dir;
}

/**
 * Assertions every settled web-request span must satisfy, whichever server
 * produced it.
 */
function ct_assert_span_shape(array $span, string $context): void
{
    ct_eq('web-request', $span['span_type'], "$context: span_type");
    ct_check(!$span['is_open'], "$context: settled reader returned a closed record");
    // Inline binding: the steps are in THIS container, not in another one.
    // `is_external` true would mean the panel has to go find a second file,
    // which is exactly what the sidecar did and what RS-M7 removes.
    ct_check(!$span['is_external'], "$context: span must be inline-bound");
    ct_check($span['shares_timeline'],
        "$context: a request shares the worker's timeline");
    ct_check($span['contiguous_on_one_thread'],
        "$context: PHP serves one request at a time per worker");
    ct_check(!$span['concurrent_with_siblings'],
        "$context: requests in one worker never overlap");
    ct_check($span['start_wall_ns'] > 0, "$context: wall-clock start recorded");
    ct_check($span['end_wall_ns'] >= $span['start_wall_ns'],
        "$context: end precedes start");
    ct_eq(0, $span['parent_span_id'], "$context: v1 spans are flat");

    $keys = array_map(static fn(array $p): string => $p[0], $span['metadata']);
    // ORDER is part of the wire contract, so the required keys are checked as
    // a prefix, not as a set.
    ct_eq(['http.method', 'http.url', 'http.status_code', 'http.duration_ms'],
        array_slice($keys, 0, 4), "$context: required metadata keys, in order");
    ct_check(ct_span_meta($span, 'http.method') !== '', "$context: http.method");
    ct_check(ct_span_meta($span, 'http.url') !== '', "$context: http.url");
    ct_check(ctype_digit(ct_span_meta($span, 'http.status_code')),
        "$context: http.status_code is numeric text");
    ct_check(ctype_digit(ct_span_meta($span, 'http.duration_ms')),
        "$context: http.duration_ms is numeric text");
    ct_eq(ct_span_meta($span, 'http.method') . ' ' . ct_span_meta($span, 'http.url'),
        $span['label'], "$context: label");
}

// ===========================================================================
// php_builtin_server_requests_land_in_one_container
// ===========================================================================

function test_php_builtin_server_requests_land_in_one_container(): void
{
    echo "\n==> php_builtin_server_requests_land_in_one_container\n";

    $dir = ct_tempdir('builtin');
    $schedule = [
        ['GET',  '/api/users',        200],
        ['POST', '/api/users',        201],
        ['GET',  '/api/users/2',      200],
        ['GET',  '/api/users/999',    404],
        ['GET',  '/api/boom',         500],
    ];

    $session = new CtBuiltinServerSession($dir);
    $session->start();
    try {
        foreach ($schedule as [$method, $path, $wantStatus]) {
            [$status] = $session->request($method, $path);
            ct_eq($wantStatus, $status, "served $method $path");
        }
    } finally {
        $session->stop();
    }

    // THE assertion of this milestone: `php -S` is one process, so the five
    // requests are five intervals of ONE recording — not five recordings.
    $containers = $session->containers();
    ct_eq(1, count($containers),
        'exactly one container for a single-worker server');
    if (count($containers) !== 1) {
        echo $session->log();
        return;
    }
    $container = $containers[0];

    $spans = ct_web_spans($container);
    ct_eq(count($schedule), count($spans), 'one span per request');
    if (count($spans) !== count($schedule)) {
        return;
    }

    // Span ids are 1-based and dense within the container.
    ct_eq(range(1, count($schedule)),
        array_map(static fn(array $s): int => $s['span_id'], $spans),
        'span ids are 1..N within the container');

    // Every request was published open and then settled: the append-only
    // stream holds two records per request, and last-record-wins collapses
    // them.  A reader without last-record-wins would report ten rows here.
    $raw = array_values(array_filter(
        ct_read_span_stream($container, false),
        static fn(array $s): bool => $s['span_type'] === 'web-request'));
    ct_eq(count($schedule) * 2, count($raw),
        'an open record and a settled record per request');
    for ($i = 0; $i < count($schedule); $i++) {
        ct_check($raw[2 * $i]['is_open'] ?? false,
            "record " . (2 * $i) . " is the open record");
        ct_check(!($raw[2 * $i + 1]['is_open'] ?? true),
            "record " . (2 * $i + 1) . " is the settled record");
        ct_eq($raw[2 * $i]['span_id'], $raw[2 * $i + 1]['span_id'],
            "open and settled records share span id");
        ct_eq(0, $raw[2 * $i]['end_step'], "open record has no end_step");
        ct_eq(0, $raw[2 * $i]['end_wall_ns'], "open record has no end time");
    }

    $counts = ct_print_full($container)['counts'];
    $stepCount = (int) $counts['steps'];
    ct_check($stepCount > 0, 'the recording captured steps at all');

    $previousEnd = null;
    foreach ($spans as $i => $span) {
        [$method, $path, $wantStatus] = $schedule[$i];
        $context = "span $i ($method $path)";
        ct_assert_span_shape($span, $context);
        ct_eq($method, ct_span_meta($span, 'http.method'), "$context: method");
        ct_eq($path, ct_span_meta($span, 'http.url'), "$context: url");
        ct_eq((string) $wantStatus, ct_span_meta($span, 'http.status_code'),
            "$context: status");
        ct_eq($wantStatus >= 400 ? 2 : 1, $span['status'],
            "$context: span status (2=error, 1=ok)");
        // The 500 carries a message and the 404 does not: status and message
        // are independent, so a span that carried one whenever it carried the
        // other would pass a weaker test than this.
        ct_eq($wantStatus === 500, ct_span_meta($span, 'error.message') !== '',
            "$context: error.message present only for the 5xx");

        // --- each span resolves to its OWN step range ---------------------
        ct_check($span['start_step'] < $stepCount,
            "$context: start_step $span[start_step] outside the container ($stepCount steps)");
        ct_check($span['end_step'] < $stepCount,
            "$context: end_step outside the container");
        // A handler that ran executed at least the steps of its own body, so
        // an empty range would mean a double-click seeks nowhere.
        ct_check($span['end_step'] > $span['start_step'],
            "$context: step range is empty ({$span['start_step']}..{$span['end_step']})");
        if ($previousEnd !== null) {
            ct_check($span['start_step'] > $previousEnd,
                "$context: starts at {$span['start_step']} but the previous " .
                "request only ended at $previousEnd — sequentially served " .
                'requests must not share steps');
        }
        $previousEnd = $span['end_step'];
    }

    // RS-M7's definition of done: no sidecar anywhere in the session.
    $sidecars = array_merge(
        glob($dir . '/*.jsonl') ?: [],
        glob($dir . '/*/*.jsonl') ?: []);
    ct_eq([], $sidecars, 'no session_manifest.jsonl / *.jsonl sidecar written');
}

// ===========================================================================
// php_fpm_multi_worker_requests_all_appear
// ===========================================================================

function test_php_fpm_multi_worker_requests_all_appear(): void
{
    echo "\n==> php_fpm_multi_worker_requests_all_appear\n";

    $dir = ct_tempdir('fpm');
    $workers = 4;
    $perWorker = 6;
    $total = $workers * $perWorker;

    // A varied schedule so a lost span cannot hide behind an identical one.
    $urls = ['/api/users', '/api/users/2', '/api/users/999', '/api/boom',
             '/static/app.css', '/api/reports/slow'];
    $requests = [];
    for ($i = 0; $i < $total; $i++) {
        $requests[] = ['GET', $urls[$i % count($urls)]];
    }

    $session = new CtFpmPoolSession($dir, $workers);
    $session->start();
    try {
        // Concurrent: every connection is opened and written before any
        // response is read, so the pool really does have several requests in
        // flight at once.
        $results = ct_fcgi_concurrent(
            $session->port(), $requests, ct_web_app_path());
        $served = 0;
        foreach ($results as $r) {
            if ($r[0] > 0) {
                $served++;
            }
        }
        ct_eq($total, $served, "all $total concurrent requests were served");
    } finally {
        $session->stop();
    }

    $containers = $session->containers();
    ct_check(count($containers) >= 2,
        'the load was spread over at least two worker recordings, got ' .
        count($containers) . ' (' . implode(', ', $containers) . ")\n" .
        $session->log());
    if (count($containers) < 1) {
        return;
    }

    // Every request appears EXACTLY once across the pool's containers.
    $seen = [];
    $urlCounts = [];
    foreach ($containers as $container) {
        $spans = ct_web_spans($container);
        ct_check(count($spans) > 0, "container $container holds spans");
        // Within one worker the ids are still 1..n and dense: each worker is
        // its own container with its own id space.
        ct_eq(range(1, count($spans)),
            array_map(static fn(array $s): int => $s['span_id'], $spans),
            "container $container: span ids are 1..N");

        $previousEnd = null;
        foreach ($spans as $i => $span) {
            $context = basename(dirname($container)) . " span $i";
            ct_assert_span_shape($span, $context);
            $key = $container . '#' . $span['span_id'];
            ct_check(!isset($seen[$key]), "$context: appears only once");
            $seen[$key] = true;
            $url = ct_span_meta($span, 'http.url');
            $urlCounts[$url] = ($urlCounts[$url] ?? 0) + 1;
            ct_check($span['end_step'] > $span['start_step'],
                "$context: non-empty step range");
            if ($previousEnd !== null) {
                ct_check($span['start_step'] > $previousEnd,
                    "$context: overlaps its predecessor in the same worker");
            }
            $previousEnd = $span['end_step'];
        }
    }

    ct_eq($total, count($seen),
        "every one of the $total requests produced exactly one span");

    // And the right ones: the schedule hit each URL the same number of times.
    $wantPerUrl = $total / count($urls);
    foreach ($urls as $url) {
        ct_eq((int) $wantPerUrl, $urlCounts[$url] ?? 0,
            "URL $url appears $wantPerUrl times across the pool");
    }

    $sidecars = array_merge(
        glob($dir . '/*.jsonl') ?: [],
        glob($dir . '/*/*.jsonl') ?: []);
    ct_eq([], $sidecars, 'no JSONL sidecar written by the pool');
}

// ===========================================================================
// php_fpm_default_control_timeout_still_writes_every_container
// ===========================================================================

/**
 * The regression guard on the SIGQUIT/SIGTERM handler in ext/codetracer_php.c.
 *
 * `php_fpm_multi_worker_requests_all_appear` above runs its pool with an
 * explicit `process_control_timeout = 10s`, and under that setting the workers
 * survive long enough to write their containers by a conventional route — so
 * that test passes with the signal handler deleted and proves nothing about
 * it.  php-fpm's DEFAULT is `process_control_timeout = 0`, under which the
 * master escalates SIGQUIT -> SIGTERM -> SIGKILL with no gap and a worker that
 * has not already written its container by the time it returns from the
 * SIGQUIT handler never writes one at all.  That is the configuration an
 * unconfigured deployment runs, and the only configuration in which the
 * handler is load-bearing.
 *
 * Measured, four static workers, all idle at stop time:
 *
 *   handler removed, timeout 10s        -> 4/4 containers   (proves nothing)
 *   handler removed, timeout default(0) -> 0/4 containers   <- this test
 *   handler present, timeout default(0) -> 4/4 containers
 *
 * The assertion is deliberately "every worker that OPENED a recording also
 * wrote its container": the `worker_<pid>` directories are created when the
 * writer opens, at the worker's first request, so they are there either way
 * and the comparison cannot be satisfied by a pool that recorded nothing.
 */
function test_php_fpm_default_control_timeout_still_writes_every_container(): void
{
    echo "\n==> php_fpm_default_control_timeout_still_writes_every_container\n";

    $dir = ct_tempdir('fpmdefault');
    $workers = 4;
    $perWorker = 6;
    $total = $workers * $perWorker;

    $urls = ['/api/users', '/api/users/2', '/api/users/999', '/api/boom',
             '/static/app.css', '/api/reports/slow'];
    $requests = [];
    for ($i = 0; $i < $total; $i++) {
        $requests[] = ['GET', $urls[$i % count($urls)]];
    }

    // The ONLY difference from php_fpm_multi_worker_requests_all_appear: no
    // process_control_timeout in the pool config, i.e. php-fpm's own default.
    $session = new CtFpmPoolSession($dir, $workers, null);
    $session->start();

    $conf = (string) file_get_contents($dir . '/php-fpm.conf');
    ct_check(!preg_match('/^\s*process_control_timeout\s*=/m', $conf),
        'the pool really does run with php-fpm\'s default control timeout');

    try {
        $results = ct_fcgi_concurrent(
            $session->port(), $requests, ct_web_app_path());
        $served = 0;
        foreach ($results as $r) {
            if ($r[0] > 0) {
                $served++;
            }
        }
        ct_eq($total, $served, "all $total concurrent requests were served");
    } finally {
        // Graceful stop, exactly as a `systemctl reload` / container stop does
        // it.  With no control timeout this is where an unhandled worker dies.
        $session->stop();
    }

    $pids = $session->workerPids();
    $containers = $session->containers();
    ct_check(count($pids) >= 2,
        'the load was spread over at least two workers, got ' . count($pids));
    // THE assertion: no worker was killed before its container reached disk.
    ct_eq(count($pids), count($containers),
        count($pids) . ' worker(s) opened a recording but only ' .
        count($containers) . ' container(s) were written under a default ' .
        "process_control_timeout — the SIGQUIT/SIGTERM handler is what closes\n" .
        $session->log());
    if (count($containers) !== count($pids)) {
        return;
    }

    // A written-but-empty container would satisfy a bare file count, so the
    // spans are read back through the canonical decoder as well.
    $seen = [];
    $urlCounts = [];
    foreach ($containers as $container) {
        $spans = ct_web_spans($container);
        ct_check(count($spans) > 0,
            "container $container holds spans");
        ct_eq(range(1, count($spans)),
            array_map(static fn(array $s): int => $s['span_id'], $spans),
            "container $container: span ids are 1..N");
        foreach ($spans as $i => $span) {
            $context = basename(dirname($container)) . " span $i";
            ct_assert_span_shape($span, $context);
            $key = $container . '#' . $span['span_id'];
            ct_check(!isset($seen[$key]), "$context: appears only once");
            $seen[$key] = true;
            $url = ct_span_meta($span, 'http.url');
            $urlCounts[$url] = ($urlCounts[$url] ?? 0) + 1;
        }
    }

    ct_eq($total, count($seen),
        "every one of the $total requests survived the hostile shutdown");
    $wantPerUrl = $total / count($urls);
    foreach ($urls as $url) {
        ct_eq((int) $wantPerUrl, $urlCounts[$url] ?? 0,
            "URL $url appears $wantPerUrl times across the pool");
    }
}

// ===========================================================================
// php_interning_shared_across_requests
// ===========================================================================

function test_php_interning_shared_across_requests(): void
{
    echo "\n==> php_interning_shared_across_requests\n";

    // The direct regression guard on the RINIT/RSHUTDOWN teardown being
    // removed.  Before RS-M7 each request opened and closed its own writer, so
    // 50 requests re-interned the application's paths and function names 50
    // times over 50 containers.  Now they share one worker recording: the
    // tables stay the size of a single request while the timeline grows.
    $baselineDir = ct_tempdir('intern1');
    $baseline = new CtBuiltinServerSession($baselineDir);
    $baseline->start();
    try {
        $baseline->request('GET', '/api/users/2');
    } finally {
        $baseline->stop();
    }
    $baselineContainers = $baseline->containers();
    ct_eq(1, count($baselineContainers), 'baseline: one container');
    if (count($baselineContainers) !== 1) {
        return;
    }
    $baseCounts = ct_print_full($baselineContainers[0])['counts'];

    $manyDir = ct_tempdir('intern50');
    $many = new CtBuiltinServerSession($manyDir);
    $many->start();
    try {
        for ($i = 0; $i < 50; $i++) {
            $many->request('GET', '/api/users/2');
        }
    } finally {
        $many->stop();
    }
    $manyContainers = $many->containers();
    ct_eq(1, count($manyContainers),
        '50 requests produced ONE container, not 50');
    if (count($manyContainers) !== 1) {
        echo $many->log();
        return;
    }
    $full = ct_print_full($manyContainers[0]);
    $manyCounts = $full['counts'];

    ct_eq(50, count(ct_web_spans($manyContainers[0])),
        '50 spans over the one worker recording');

    // --- the interning evidence ----------------------------------------
    ct_eq((int) $baseCounts['paths'], (int) $manyCounts['paths'],
        'path table is the same size after 50 requests as after 1');
    ct_eq(1, (int) $manyCounts['paths'],
        "the app's single file is interned exactly once for the worker");
    ct_eq((int) $baseCounts['functions'], (int) $manyCounts['functions'],
        'function table is the same size after 50 requests as after 1');
    ct_eq((int) $baseCounts['varnames'], (int) $manyCounts['varnames'],
        'varname table is the same size after 50 requests as after 1');
    ct_eq((int) $baseCounts['types'], (int) $manyCounts['types'],
        'type table is the same size after 50 requests as after 1');

    ct_eq([ct_web_app_path()], $full['paths'],
        'the only interned path is the app itself');
    ct_eq(count($full['functions']), count(array_unique($full['functions'])),
        'no function name is interned twice');

    // The timeline DID grow, so the test cannot be satisfied by recording one
    // request and dropping the other 49.
    ct_check((int) $manyCounts['steps'] >= 40 * (int) $baseCounts['steps'],
        sprintf('steps grew with the requests: %d steps for 50 requests vs ' .
            '%d for 1', $manyCounts['steps'], $baseCounts['steps']));

    printf("  [evidence] 1 request:  paths=%d functions=%d steps=%d\n",
        $baseCounts['paths'], $baseCounts['functions'], $baseCounts['steps']);
    printf("  [evidence] 50 requests: paths=%d functions=%d steps=%d\n",
        $manyCounts['paths'], $manyCounts['functions'], $manyCounts['steps']);
}

// ---------------------------------------------------------------------------

if (!function_exists('codetracer_spans_json')) {
    fwrite(STDERR,
        "the codetracer extension is not loaded; run this suite as\n" .
        "  php -d extension=ext/modules/codetracer.so tests/test_request_spans.php\n");
    exit(1);
}
if (!is_executable(ct_web_extension_path())) {
    fwrite(STDERR, 'extension not built at ' . ct_web_extension_path() .
        " (run `just build`)\n");
    exit(1);
}

test_php_builtin_server_requests_land_in_one_container();
test_php_fpm_multi_worker_requests_all_appear();
test_php_fpm_default_control_timeout_still_writes_every_container();
test_php_interning_shared_across_requests();

echo "\n" . str_repeat('=', 64) . "\n";
echo "Tests: $ctTests, Passed: $ctPassed, Failed: $ctFailed\n";
if ($ctFailed > 0) {
    exit(1);
}
echo "ALL REQUEST-SPAN TESTS PASSED\n";

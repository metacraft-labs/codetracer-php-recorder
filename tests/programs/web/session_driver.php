<?php
/**
 * RS-M7 web session harness — shared by the integration tests, the demo
 * recipe and the fixture recorder so the three cannot drift.
 *
 * Two server shapes, one interface:
 *
 *   * `CtBuiltinServerSession` — `php -S`, a single process.  One worker,
 *     therefore ONE container holding one continuous recording with one span
 *     per request.
 *   * `CtFpmPoolSession` — a real PHP-FPM pool with several workers.  Each
 *     worker is its own process and therefore its own continuous recording;
 *     the pool's requests are spread across those containers.
 *
 * Shutdown is load-bearing, because the CTFS multi-stream writer builds the
 * container in memory and only `trace_writer_close()` puts bytes on disk.
 * Three measured facts shape the code below (all observed with
 * CODETRACER_DEBUG against php 8.4.21):
 *
 *   1. The built-in server installs a handler for SIGINT only.  SIGINT is
 *      what makes it leave its accept loop and run `php_module_shutdown()`;
 *      SIGTERM just kills it and the recording is lost.
 *   2. PHP-FPM pool children do NOT reliably reach `php_module_shutdown()`,
 *      and do not run `atexit` handlers either — on a graceful pool stop only
 *      one child in three did.  The extension therefore closes its container
 *      from the SIGQUIT/SIGTERM handler it installs per worker.
 *   3. php-fpm's default `process_control_timeout = 0` makes the master
 *      escalate SIGQUIT -> SIGTERM -> SIGKILL with no gap, so a worker that
 *      waits for `php_module_shutdown()` never gets to shut down at all — the
 *      whole reason fact 2's handler writes the container from the signal
 *      itself.  `CtFpmPoolSession` can therefore be built either way: with an
 *      explicit `process_control_timeout` (the friendly configuration) or with
 *      php-fpm's own default (the hostile one, which is what an unconfigured
 *      deployment actually runs).  See
 *      `php_fpm_default_control_timeout_still_writes_every_container` in
 *      tests/test_request_spans.php, which fails outright if the extension
 *      stops handling the signal.
 *
 * A SIGKILL still skips everything and the worker's whole recording is lost —
 * see the note on `ct_worker_close()` in ext/codetracer_php.c.
 */

declare(strict_types=1);

/** The 8-request demo schedule, shared with the Python and Ruby recorders. */
const CT_DEMO_REQUESTS = [
    ['GET',  '/api/users'],
    ['POST', '/api/users'],
    ['GET',  '/api/users/2'],
    ['GET',  '/static/app.css'],
    ['GET',  '/api/users/999'],
    ['GET',  '/api/reports/slow'],
    ['GET',  '/api/boom'],
    ['GET',  '/api/users'],
];

function ct_web_repo_root(): string
{
    return dirname(__DIR__, 3);
}

function ct_web_extension_path(): string
{
    return ct_web_repo_root() . '/ext/modules/codetracer.so';
}

function ct_web_trace_format_nim_dir(): string
{
    $env = getenv('TRACE_FORMAT_NIM_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return ct_web_repo_root() . '/../codetracer-trace-format-nim';
}

function ct_web_ld_library_path(): string
{
    $cur = getenv('LD_LIBRARY_PATH');
    return ct_web_trace_format_nim_dir() . ($cur ? ':' . $cur : '');
}

function ct_web_app_path(): string
{
    return __DIR__ . '/app.php';
}

/** A free TCP port on the loopback interface. */
function ct_web_free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("cannot bind a loopback port: $errstr ($errno)");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    if ($port <= 0) {
        throw new RuntimeException("could not parse a port out of '$name'");
    }
    return $port;
}

function ct_web_wait_for_port(int $port, float $timeoutSeconds = 15.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $conn = @stream_socket_client(
            "tcp://127.0.0.1:$port", $errno, $errstr, 0.25);
        if ($conn !== false) {
            fclose($conn);
            return;
        }
        usleep(50_000);
    }
    throw new RuntimeException("server never started listening on port $port");
}

/**
 * Issue one HTTP request and return [status, body].
 *
 * Deliberately raw sockets rather than `file_get_contents`: the demo drives
 * 4xx and 5xx responses on purpose and a stream wrapper would turn those into
 * warnings and a `false` return.
 */
function ct_web_http(int $port, string $method, string $path,
                     float $timeoutSeconds = 30.0): array
{
    $conn = @stream_socket_client(
        "tcp://127.0.0.1:$port", $errno, $errstr, $timeoutSeconds);
    if ($conn === false) {
        throw new RuntimeException("connect to :$port failed: $errstr ($errno)");
    }
    stream_set_timeout($conn, (int) $timeoutSeconds);
    $req = "$method $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n"
         . "Connection: close\r\nContent-Length: 0\r\n\r\n";
    fwrite($conn, $req);
    $raw = stream_get_contents($conn);
    fclose($conn);
    if ($raw === false || $raw === '') {
        throw new RuntimeException("$method $path: empty response");
    }
    $status = 0;
    if (preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");
    $body = $split === false ? '' : substr($raw, $split + 4);
    return [$status, $body];
}

/** The environment every recorded server process is started with. */
function ct_web_env(string $outputDir, array $extra = []): array
{
    // proc_open with an explicit env REPLACES the environment, so anything
    // the server processes need has to be listed here.
    $passthrough = [];
    foreach (['CODETRACER_DEBUG'] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            $passthrough[$name] = $value;
        }
    }
    return array_merge([
        'CODETRACER_ENABLED' => '1',
        'CODETRACER_OUTPUT_DIR' => $outputDir,
        // Naming the program up front is what lets PHP_MINIT open the writer
        // immediately instead of waiting for the first request, and it fixes
        // the container's file name to `app.ct`.
        'CODETRACER_PROGRAM' => ct_web_app_path(),
        'LD_LIBRARY_PATH' => ct_web_ld_library_path(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'TMPDIR' => getenv('TMPDIR') ?: '/tmp',
    ], $passthrough, $extra);
}

abstract class CtWebSession
{
    protected string $outputDir;
    protected int $port;
    /** @var resource|null */
    protected $proc = null;
    protected array $pipes = [];
    protected string $log = '';

    public function __construct(string $outputDir)
    {
        $this->outputDir = $outputDir;
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RuntimeException("cannot create $outputDir");
        }
        $this->port = ct_web_free_port();
    }

    public function port(): int
    {
        return $this->port;
    }

    public function log(): string
    {
        return $this->log;
    }

    public function request(string $method, string $path): array
    {
        return ct_web_http($this->port, $method, $path);
    }

    /** Every container the session's workers wrote, sorted. */
    public function containers(): array
    {
        $found = glob($this->outputDir . '/worker_*/*.ct') ?: [];
        sort($found);
        return $found;
    }

    /**
     * The pids of every worker that opened a recording, read off the
     * `worker_<pid>` directories the extension creates when it opens a
     * writer.  Used to wait for the workers themselves rather than only for
     * the process that spawned them.
     */
    public function workerPids(): array
    {
        $pids = [];
        foreach (glob($this->outputDir . '/worker_*') ?: [] as $dir) {
            if (preg_match('#/worker_(\d+)$#', $dir, $m) === 1) {
                $pids[] = (int) $m[1];
            }
        }
        sort($pids);
        return $pids;
    }

    /**
     * Block until every recording worker has really exited.
     *
     * The pool master leaves before its children are reaped, and a worker
     * writes its container from PHP_MSHUTDOWN — after the master is gone.
     * Reading the output directory the moment the master exits therefore sees
     * an empty directory even on a completely healthy run.  This waits for the
     * workers, not for their parent; a worker that dies WITHOUT writing still
     * fails the caller's assertions.
     */
    protected function waitForWorkers(float $timeoutSeconds = 30.0): void
    {
        $pids = $this->workerPids();
        if (!$pids) {
            return;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $alive = false;
            foreach ($pids as $pid) {
                // The workers are not our children (the pool master forked
                // them), so posix_kill(pid, 0) is the liveness test, not
                // proc_get_status.
                if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
                    $alive = true;
                    break;
                }
                if (!function_exists('posix_kill') && file_exists("/proc/$pid")) {
                    $alive = true;
                    break;
                }
            }
            if (!$alive) {
                return;
            }
            usleep(50_000);
        }
    }

    /** The single container of a single-worker session. */
    public function container(): string
    {
        $found = $this->containers();
        if (count($found) !== 1) {
            throw new RuntimeException(sprintf(
                "expected exactly one container in %s, got %d (%s)\n--- server log ---\n%s",
                $this->outputDir, count($found), implode(', ', $found), $this->log));
        }
        return $found[0];
    }

    abstract public function start(): void;

    abstract public function stop(): void;

    protected function drainLog(): void
    {
        foreach ($this->pipes as $fd => $pipe) {
            if ($fd === 0 || !is_resource($pipe)) {
                continue;
            }
            stream_set_blocking($pipe, false);
            $chunk = stream_get_contents($pipe);
            if (is_string($chunk)) {
                $this->log .= $chunk;
            }
        }
    }
}

final class CtBuiltinServerSession extends CtWebSession
{
    public function start(): void
    {
        $cmd = [
            'php',
            '-d', 'extension=' . ct_web_extension_path(),
            '-S', '127.0.0.1:' . $this->port,
            ct_web_app_path(),
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->proc = proc_open($cmd, $descriptors, $this->pipes,
            ct_web_repo_root(), ct_web_env($this->outputDir));
        if (!is_resource($this->proc)) {
            throw new RuntimeException('failed to spawn php -S');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        ct_web_wait_for_port($this->port);
    }

    public function stop(): void
    {
        if (!is_resource($this->proc)) {
            return;
        }
        // SIGINT, not SIGTERM: the built-in server installs a handler for
        // SIGINT only, and that handler is what makes it leave the accept
        // loop and reach php_module_shutdown() — where the container is
        // actually written.
        proc_terminate($this->proc, SIGINT);
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->proc);
            if (!$status['running']) {
                break;
            }
            $this->drainLog();
            usleep(50_000);
        }
        $this->drainLog();
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        proc_close($this->proc);
        $this->proc = null;
    }
}

final class CtFpmPoolSession extends CtWebSession
{
    private int $workers;
    private string $confPath;
    private string $pidPath;
    private int $masterPid = 0;
    /** `null` = leave php-fpm's own default (0), i.e. no grace period. */
    private ?string $processControlTimeout;

    /**
     * @param ?string $processControlTimeout php-fpm's `process_control_timeout`
     *        for this pool.  `'10s'` gives workers a grace period in which a
     *        conventional shutdown path can run; `null` omits the directive and
     *        leaves php-fpm's default of 0, under which the master escalates
     *        SIGQUIT -> SIGTERM -> SIGKILL with no gap and only a worker that
     *        writes its container FROM the signal handler survives.
     */
    public function __construct(string $outputDir, int $workers = 4,
                                ?string $processControlTimeout = '10s')
    {
        parent::__construct($outputDir);
        $this->workers = $workers;
        $this->processControlTimeout = $processControlTimeout;
        $this->confPath = $outputDir . '/php-fpm.conf';
        $this->pidPath = $outputDir . '/php-fpm.pid';
    }

    public function workers(): int
    {
        return $this->workers;
    }

    private function writeConf(): void
    {
        // Without an explicit control timeout the master escalates
        // SIGQUIT -> SIGTERM -> SIGKILL with no gap between them, so a worker
        // never gets to finish shutting down the conventional way.  Which of
        // the two the pool runs with is the variable the default-timeout test
        // turns; omitting the directive is NOT the same as writing `0`, since
        // this must exercise what an unconfigured php-fpm really does.
        $controlTimeout = $this->processControlTimeout === null
            ? '; process_control_timeout left at php-fpm\'s default (0)'
            : 'process_control_timeout = ' . $this->processControlTimeout;

        // `pm = static` with `pm.max_children = N` guarantees N workers are
        // alive from the start, which is what makes "several workers under
        // concurrent load" a property of the run rather than a hope.
        $conf = <<<CONF
        [global]
        pid = {$this->pidPath}
        error_log = {$this->outputDir}/php-fpm.log
        daemonize = no
        {$controlTimeout}

        [ct]
        listen = 127.0.0.1:{$this->port}
        pm = static
        pm.max_children = {$this->workers}
        catch_workers_output = yes
        clear_env = no
        CONF;
        file_put_contents($this->confPath, $conf . "\n");
    }

    public function start(): void
    {
        $this->writeConf();
        $cmd = [
            'php-fpm',
            '-y', $this->confPath,
            '-d', 'extension=' . ct_web_extension_path(),
            '-F',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->proc = proc_open($cmd, $descriptors, $this->pipes,
            ct_web_repo_root(), ct_web_env($this->outputDir));
        if (!is_resource($this->proc)) {
            throw new RuntimeException('failed to spawn php-fpm');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        ct_web_wait_for_port($this->port);
        $status = proc_get_status($this->proc);
        $this->masterPid = (int) $status['pid'];
    }

    /**
     * One FastCGI request over the pool's TCP socket.
     *
     * Written by hand because there is no FastCGI client in PHP's core and
     * this test must talk to a real php-fpm pool, not to a stand-in.
     */
    public function fcgiRequest(string $method, string $path): array
    {
        return ct_fcgi_request($this->port, $method, $path, ct_web_app_path());
    }

    public function stop(): void
    {
        if (!is_resource($this->proc)) {
            return;
        }
        // SIGQUIT is php-fpm's graceful stop: children finish what they are
        // doing, leave the accept loop and run php_module_shutdown(), which
        // is what writes each worker's container.
        proc_terminate($this->proc, SIGQUIT);
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->proc);
            if (!$status['running']) {
                break;
            }
            $this->drainLog();
            usleep(50_000);
        }
        $this->drainLog();
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        proc_close($this->proc);
        $this->proc = null;
        $this->waitForWorkers();
    }
}

// ---------------------------------------------------------------------------
// Minimal FastCGI client
// ---------------------------------------------------------------------------

const CT_FCGI_BEGIN_REQUEST = 1;
const CT_FCGI_PARAMS = 4;
const CT_FCGI_STDIN = 5;
const CT_FCGI_STDOUT = 6;
const CT_FCGI_STDERR = 7;
const CT_FCGI_END_REQUEST = 3;
const CT_FCGI_RESPONDER = 1;

function ct_fcgi_record(int $type, int $requestId, string $content): string
{
    $len = strlen($content);
    $padding = (8 - ($len % 8)) % 8;
    return pack('CCnnCC', 1, $type, $requestId, $len, $padding, 0)
        . $content . str_repeat("\0", $padding);
}

function ct_fcgi_pair(string $name, string $value): string
{
    $encode = static function (int $len): string {
        return $len < 128
            ? chr($len)
            : pack('N', $len | 0x80000000);
    };
    return $encode(strlen($name)) . $encode(strlen($value)) . $name . $value;
}

/** The bytes of one complete FastCGI responder request. */
function ct_fcgi_payload(string $method, string $uri,
                         string $scriptFilename, int $port): string
{
    $requestId = 1;

    $out = ct_fcgi_record(CT_FCGI_BEGIN_REQUEST, $requestId,
        pack('nCx5', CT_FCGI_RESPONDER, 0));

    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $query = parse_url($uri, PHP_URL_QUERY) ?: '';
    $params = [
        'GATEWAY_INTERFACE' => 'FastCGI/1.0',
        'REQUEST_METHOD' => $method,
        'SCRIPT_FILENAME' => $scriptFilename,
        'SCRIPT_NAME' => $path,
        'REQUEST_URI' => $uri,
        'DOCUMENT_URI' => $path,
        'QUERY_STRING' => $query,
        'SERVER_SOFTWARE' => 'codetracer-fcgi-test',
        'REMOTE_ADDR' => '127.0.0.1',
        'REMOTE_PORT' => '9999',
        'SERVER_ADDR' => '127.0.0.1',
        'SERVER_PORT' => (string) $port,
        'SERVER_NAME' => 'localhost',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'CONTENT_TYPE' => '',
        'CONTENT_LENGTH' => '0',
    ];
    $encoded = '';
    foreach ($params as $k => $v) {
        $encoded .= ct_fcgi_pair($k, (string) $v);
    }
    $out .= ct_fcgi_record(CT_FCGI_PARAMS, $requestId, $encoded);
    $out .= ct_fcgi_record(CT_FCGI_PARAMS, $requestId, '');
    $out .= ct_fcgi_record(CT_FCGI_STDIN, $requestId, '');
    return $out;
}

/** Decode a FastCGI response stream into [status, body, stderr]. */
function ct_fcgi_parse(string $raw): array
{
    $stdout = '';
    $stderr = '';
    $offset = 0;
    $len = strlen((string) $raw);
    while ($offset + 8 <= $len) {
        $header = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved',
            substr((string) $raw, $offset, 8));
        $offset += 8;
        $content = substr((string) $raw, $offset, $header['contentLength']);
        $offset += $header['contentLength'] + $header['paddingLength'];
        if ($header['type'] === CT_FCGI_STDOUT) {
            $stdout .= $content;
        } elseif ($header['type'] === CT_FCGI_STDERR) {
            $stderr .= $content;
        } elseif ($header['type'] === CT_FCGI_END_REQUEST) {
            break;
        }
    }

    $status = 200;
    $split = strpos($stdout, "\r\n\r\n");
    $headers = $split === false ? $stdout : substr($stdout, 0, $split);
    $body = $split === false ? '' : substr($stdout, $split + 4);
    if (preg_match('#^Status:\s*(\d{3})#mi', $headers, $m) === 1) {
        $status = (int) $m[1];
    }
    return [$status, $body, $stderr];
}

/**
 * Issue one FastCGI request and return [status, body, stderr].
 *
 * Written by hand because there is no FastCGI client in PHP's core and these
 * tests must talk to a real php-fpm pool, not to a stand-in.
 */
function ct_fcgi_request(int $port, string $method, string $uri,
                         string $scriptFilename, float $timeout = 60.0): array
{
    $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, $timeout);
    if ($conn === false) {
        throw new RuntimeException("fcgi connect :$port failed: $errstr ($errno)");
    }
    stream_set_timeout($conn, (int) $timeout);
    fwrite($conn, ct_fcgi_payload($method, $uri, $scriptFilename, $port));
    $raw = stream_get_contents($conn);
    fclose($conn);
    return ct_fcgi_parse((string) $raw);
}

/**
 * Issue many FastCGI requests CONCURRENTLY and return one [status, body] per
 * request, in the order given.
 *
 * Every connection is opened and written before any response is read, so a
 * pool of N workers really does have N requests in flight at once — which is
 * the point of the multi-worker test.  A sequential client would let one
 * worker serve everything and the test would prove nothing.
 *
 * @param array<int, array{0:string,1:string}> $requests [method, uri] pairs
 * @return array<int, array{0:int,1:string}>
 */
function ct_fcgi_concurrent(int $port, array $requests, string $scriptFilename,
                            float $timeout = 60.0): array
{
    $conns = [];
    foreach ($requests as $i => [$method, $uri]) {
        $conn = @stream_socket_client(
            "tcp://127.0.0.1:$port", $errno, $errstr, $timeout);
        if ($conn === false) {
            throw new RuntimeException("fcgi connect :$port failed: $errstr ($errno)");
        }
        stream_set_timeout($conn, (int) $timeout);
        fwrite($conn, ct_fcgi_payload($method, $uri, $scriptFilename, $port));
        $conns[$i] = $conn;
    }

    $results = [];
    foreach ($conns as $i => $conn) {
        $raw = stream_get_contents($conn);
        fclose($conn);
        $results[$i] = ct_fcgi_parse((string) $raw);
    }
    ksort($results);
    return $results;
}


// ---------------------------------------------------------------------------
// Span reading — through the canonical Nim decoder, never a local one
// ---------------------------------------------------------------------------

/**
 * The span stream of a finished container, decoded by `ct_spans_json` —
 * the same reader `ct print -f http` uses, so a test can never agree with a
 * writer bug.  Requires the extension to be loaded in THIS process.
 */
function ct_read_span_stream(string $container, bool $settled = true): array
{
    if (!function_exists('codetracer_spans_json')) {
        throw new RuntimeException(
            'the codetracer extension is not loaded in this process; run this ' .
            'test with -d extension=ext/modules/codetracer.so');
    }
    $json = codetracer_spans_json($container, $settled);
    if ($json === null) {
        throw new RuntimeException("ct_spans_json failed for $container");
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/** All `web-request` spans of a container, in span-id order. */
function ct_web_spans(string $container): array
{
    $spans = ct_read_span_stream($container, true);
    return array_values(array_filter(
        $spans,
        static fn(array $s): bool => $s['span_type'] === 'web-request'
    ));
}

/** One metadata value of a span; metadata is an ARRAY of [key, value] pairs. */
function ct_span_meta(array $span, string $key, string $default = ''): string
{
    foreach ($span['metadata'] ?? [] as $pair) {
        if ($pair[0] === $key) {
            return $pair[1];
        }
    }
    return $default;
}

/** Decode a container with ct-print --full (the interning tables live there). */
function ct_print_full(string $container): array
{
    $ctPrint = ct_web_trace_format_nim_dir() . '/ct-print';
    if (!is_executable($ctPrint)) {
        throw new RuntimeException(
            "ct-print not built at $ctPrint (cd " . ct_web_trace_format_nim_dir() .
            " && nimble buildCtPrint)");
    }
    $out = $container . '.full.json';
    $cmd = sprintf('LD_LIBRARY_PATH=%s %s --full %s > %s 2>/dev/null',
        escapeshellarg(ct_web_ld_library_path()),
        escapeshellarg($ctPrint),
        escapeshellarg($container),
        escapeshellarg($out));
    shell_exec($cmd);
    if (!file_exists($out)) {
        throw new RuntimeException("ct-print --full failed for $container");
    }
    $raw = (string) file_get_contents($out);
    return json_decode(mb_convert_encoding($raw, 'UTF-8', 'UTF-8'),
        true, 512, JSON_THROW_ON_ERROR);
}

/** Record the 8-request demo session into $traceDir; returns the container. */
function ct_record_demo_session(string $traceDir): string
{
    $session = new CtBuiltinServerSession($traceDir);
    $session->start();
    try {
        foreach (CT_DEMO_REQUESTS as [$method, $path]) {
            $session->request($method, $path);
        }
    } finally {
        $session->stop();
    }
    return $session->container();
}

// ---------------------------------------------------------------------------
// CLI: record a session into a directory and optionally print its spans
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $traceDir = null;
    $printSpans = false;
    for ($i = 1; $i < $argc; $i++) {
        if ($argv[$i] === '--trace-dir' && isset($argv[$i + 1])) {
            $traceDir = $argv[++$i];
        } elseif ($argv[$i] === '--print-spans') {
            $printSpans = true;
        }
    }
    if ($traceDir === null) {
        fwrite(STDERR, "usage: session_driver.php --trace-dir DIR [--print-spans]\n");
        exit(2);
    }
    $container = ct_record_demo_session($traceDir);
    echo "[session] recorded " . count(CT_DEMO_REQUESTS) .
        " requests into $container\n";
    if ($printSpans) {
        foreach (ct_web_spans($container) as $span) {
            printf("  span %3d  %-28s status=%3s %5sms steps %d..%d route=%s\n",
                $span['span_id'],
                $span['label'],
                ct_span_meta($span, 'http.status_code', '?'),
                ct_span_meta($span, 'http.duration_ms', '?'),
                $span['start_step'],
                $span['end_step'],
                ct_span_meta($span, 'http.route', '-'));
        }
    }
}

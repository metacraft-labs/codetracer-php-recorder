<?php
/**
 * CodeTracer HTTP Request Span Tracking for PHP.
 *
 * Captures HTTP request metadata ($_SERVER variables) and writes
 * a span to the JSONL manifest file. Each PHP request invocation
 * is a span.
 */

class CodeTracerSpan {
    private static $instance = null;
    private $spanId;
    private $startTime;
    private $method;
    private $url;
    private $manifestPath;

    public static function begin(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->startTime = hrtime(true);
        $this->spanId = 'span_php_' . getmypid() . '_' . $this->startTime;
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $this->url = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? 'unknown';
        $this->manifestPath = getenv('CODETRACER_SPAN_MANIFEST') ?: '/tmp/codetracer_spans.jsonl';
    }

    public function end(int $statusCode = 200): void {
        $durationMs = (int)((hrtime(true) - $this->startTime) / 1_000_000);

        $span = [
            'id' => $this->spanId,
            'label' => "{$this->method} {$this->url}",
            'span_type' => 'web-request',
            'metadata' => [
                'http.method' => $this->method,
                'http.url' => $this->url,
                'http.status_code' => (string)$statusCode,
                'http.duration_ms' => (string)$durationMs,
            ],
            'status' => $statusCode >= 400 ? 'error' : 'ok',
            'end_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->writeSpan($span);
    }

    public function getSpanId(): string {
        return $this->spanId;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getUrl(): string {
        return $this->url;
    }

    /**
     * Return elapsed time since span began, in milliseconds.
     * Useful for the session manifest entry written by web_bootstrap.php.
     */
    public function getDurationMs(): int {
        return (int)((hrtime(true) - $this->startTime) / 1_000_000);
    }

    private function writeSpan(array $span): void {
        $line = json_encode($span, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->manifestPath, $line, FILE_APPEND | LOCK_EX);
    }

    public static function reset(): void {
        self::$instance = null;
    }
}

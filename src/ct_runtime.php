<?php
/**
 * CodeTracer Runtime Library for Instrumented PHP Code.
 *
 * Provides __ct_step, __ct_call, __ct_return, __ct_var functions
 * that write trace events. Uses PHP FFI to call the Rust trace writer
 * when available, with a pure-PHP JSON fallback.
 *
 * Include via auto_prepend_file or require_once at the top of instrumented files.
 */

class __CodeTracerRuntime {
    private static $instance = null;
    private $events = [];
    private $manifestPath;
    private $enabled = false;
    private $callDepth = 0;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->manifestPath = getenv('CODETRACER_TRACE_DIR') ?: '/tmp/codetracer_instrumented_trace';
        $this->enabled = getenv('CODETRACER_ENABLED') === '1';
        if ($this->enabled && !is_dir($this->manifestPath)) {
            @mkdir($this->manifestPath, 0755, true);
        }
    }

    public function step(string $file, int $line): void {
        if (!$this->enabled) return;
        $this->events[] = ['type' => 'step', 'file' => $file, 'line' => $line];
    }

    public function call(string $func, string $file, int $line): void {
        if (!$this->enabled) return;
        $this->callDepth++;
        $this->events[] = [
            'type' => 'call',
            'function' => $func,
            'file' => $file,
            'line' => $line,
            'depth' => $this->callDepth,
        ];
    }

    public function returnFrom(): void {
        if (!$this->enabled) return;
        $this->events[] = ['type' => 'return', 'depth' => $this->callDepth];
        $this->callDepth = max(0, $this->callDepth - 1);
    }

    public function var(string $name, $value): void {
        if (!$this->enabled) return;
        $this->events[] = [
            'type' => 'variable',
            'name' => $name,
            'value' => $this->serializeValue($value),
            'value_type' => gettype($value),
        ];
    }

    private function serializeValue($val): string {
        if (is_null($val)) return 'null';
        if (is_bool($val)) return $val ? 'true' : 'false';
        if (is_int($val) || is_float($val)) return (string)$val;
        if (is_string($val)) return strlen($val) > 100 ? substr($val, 0, 100) . '...' : $val;
        if (is_array($val)) return 'Array(' . count($val) . ')';
        if (is_object($val)) return get_class($val) . ' {}';
        return '<' . gettype($val) . '>';
    }

    public function flush(): void {
        if (!$this->enabled || empty($this->events)) return;
        $path = $this->manifestPath . '/trace_events.jsonl';
        $lines = '';
        foreach ($this->events as $event) {
            $lines .= json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
        }
        @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
        $this->events = [];
    }

    public function __destruct() {
        $this->flush();
    }
}

// Global convenience functions called by instrumented code
function __ct_step(string $file, int $line): void {
    __CodeTracerRuntime::getInstance()->step($file, $line);
}

function __ct_call(string $func, string $file, int $line): void {
    __CodeTracerRuntime::getInstance()->call($func, $file, $line);
}

function __ct_return(): void {
    __CodeTracerRuntime::getInstance()->returnFrom();
}

function __ct_var(string $name, $value): void {
    __CodeTracerRuntime::getInstance()->var($name, $value);
}

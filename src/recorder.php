<?php
/**
 * CodeTracer PHP Recorder -- function call tracing.
 *
 * Uses register_tick_function for basic step tracking.
 * Full implementation will use a C extension hooking zend_execute_ex.
 *
 * This is a placeholder.  Request spans are produced by the C extension
 * (`ext/codetracer_php.c`) and land in the container's own `spans.dat`
 * stream; the pure-PHP `span.php` that used to write them to a JSONL sidecar
 * was removed in RS-M12.
 */

class CodeTracerRecorder {
    private static $events = [];
    private static $enabled = false;

    public static function enable(): void {
        self::$enabled = true;
        // register_tick_function would go here for basic tracing
    }

    public static function disable(): void {
        self::$enabled = false;
    }

    public static function getEvents(): array {
        return self::$events;
    }
}

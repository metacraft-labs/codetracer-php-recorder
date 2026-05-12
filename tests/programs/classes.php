<?php
/**
 * Class / inheritance / $this coverage.
 *
 * - Base class with constructor, instance method, $this access.
 * - Derived class overriding a method and calling parent::.
 * - Static method.
 * - All methods invoked under the recorder so zend_execute_ex sees
 *   the per-method frame and emits register_call for each.
 *
 * Per recorder-test-requirements.md §2 PHP-specific row, classes
 * are required coverage.  This program is the minimum that exercises
 * a non-trivial method dispatch (virtual call via parent::, plus a
 * static-method call) without pulling in the full trait / interface
 * matrix (those have their own test program slots tracked in the
 * compliance matrix follow-ups).
 */

class Greeter {
    private string $prefix;

    public function __construct(string $prefix) {
        $this->prefix = $prefix;
    }

    public function greet(string $name): string {
        return $this->prefix . ", " . $name . "!";
    }

    public static function shout(string $msg): string {
        return strtoupper($msg);
    }
}

class LoudGreeter extends Greeter {
    public function greet(string $name): string {
        $base = parent::greet($name);
        return self::shout($base);
    }
}

function run(): array {
    $g = new Greeter("Hello");
    $lg = new LoudGreeter("Hi");
    return [
        'plain' => $g->greet("World"),
        'loud'  => $lg->greet("World"),
        'static'=> Greeter::shout("done"),
    ];
}

$res = run();
foreach ($res as $k => $v) {
    echo "$k=$v\n";
}

<?php
// Test script for variable capture verification.
// Run with: scripts/run_with_tracing.sh tests/test_variables.php

function add($a, $b) {
    $sum = $a + $b;
    return $sum;
}

function greet($name) {
    $message = "Hello, $name!";
    return $message;
}

function process_array($items) {
    $total = count($items);
    return $total;
}

class Calculator {
    public function multiply($x, $y) {
        return $x * $y;
    }
}

// Exercise the functions
$result1 = add(3, 7);
$result2 = greet("Alice");
$result3 = process_array([1, 2, 3, 4, 5]);
$calc = new Calculator();
$result4 = $calc->multiply(6, 8);

echo "add(3, 7) = $result1\n";
echo "greet('Alice') = $result2\n";
echo "process_array([1,2,3,4,5]) = $result3\n";
echo "multiply(6, 8) = $result4\n";

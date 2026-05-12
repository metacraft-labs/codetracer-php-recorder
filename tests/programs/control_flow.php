<?php
/**
 * Control-flow coverage program.
 *
 * Exercises if/elseif/else, while, do-while, for, switch, match,
 * break, continue (every PHP control construct that has observable
 * trace consequences via zend_execute_ex on the function-entry hook).
 *
 * Per-construct assertions live in test_e2e.php — this program is
 * structured so each branch decision flips a variable that surfaces
 * in the trace, making the branch coverage visible without needing
 * per-opcode step granularity (which the PHP recorder does not yet
 * provide; see AUDIT-CTFS-2026-05.md §"Per-opcode step granularity").
 */

function classify(int $n): string {
    if ($n < 0) {
        return "negative";
    } elseif ($n === 0) {
        return "zero";
    } else {
        return "positive";
    }
}

function while_sum(int $limit): int {
    $i = 0;
    $total = 0;
    while ($i < $limit) {
        $total += $i;
        $i++;
    }
    return $total;
}

function do_while_sum(int $limit): int {
    $i = 0;
    $total = 0;
    do {
        $total += $i;
        $i++;
    } while ($i < $limit);
    return $total;
}

function for_sum(int $limit): int {
    $total = 0;
    for ($i = 0; $i < $limit; $i++) {
        $total += $i;
    }
    return $total;
}

function break_continue(int $limit): int {
    $total = 0;
    for ($i = 0; $i < $limit; $i++) {
        if ($i === 2) { continue; }
        if ($i === 5) { break; }
        $total += $i;
    }
    return $total;
}

function switch_select(int $n): string {
    switch ($n) {
        case 1: return "one";
        case 2: return "two";
        case 3:
        case 4: return "three-or-four";
        default: return "other";
    }
}

function match_select(int $n): string {
    return match (true) {
        $n === 1 => "one",
        $n === 2 => "two",
        $n === 3, $n === 4 => "three-or-four",
        default => "other",
    };
}

function run_control_flow(): array {
    $results = [];
    $results['classify_neg'] = classify(-5);
    $results['classify_zero'] = classify(0);
    $results['classify_pos'] = classify(7);
    $results['while_sum'] = while_sum(5);          // 0+1+2+3+4 = 10
    $results['do_while_sum'] = do_while_sum(4);    // 0+1+2+3 = 6
    $results['for_sum'] = for_sum(6);              // 0+1+2+3+4+5 = 15
    $results['break_continue'] = break_continue(10); // 0+1+3+4 = 8 (skip 2, break at 5)
    $results['switch_one'] = switch_select(1);
    $results['switch_default'] = switch_select(99);
    $results['match_two'] = match_select(2);
    $results['match_default'] = match_select(99);
    return $results;
}

$res = run_control_flow();
foreach ($res as $k => $v) {
    echo "$k=$v\n";
}

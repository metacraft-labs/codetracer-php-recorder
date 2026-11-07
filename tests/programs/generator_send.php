<?php
/**
 * Generator coverage for values flowing INTO a generator.
 *
 * `Generator::send($v)` resumes a generator and makes `$v` the value of the
 * `yield` expression it is suspended at.  The recorder must show both
 * directions: what each suspension yielded (the resumed call's return
 * value) and what each resumption received (the `<sent>` value on the
 * resumed call's entry step).
 *
 * Kept apart from generators.php so that program's exact pins stay about
 * `foreach` consumption.
 */

function accumulator(): \Generator {
    $total = 0;
    while (true) {
        $x = yield $total;
        if ($x === null) {
            return $total;
        }
        $total += $x;
    }
}

function drive_accumulator(): int {
    $acc = accumulator();
    $acc->current();   // runs to the first yield, which yields 0
    $acc->send(5);     // $x = 5, yields 5
    $acc->send(7);     // $x = 7, yields 12
    $acc->send(null);  // $x = null, returns 12
    return $acc->getReturn();
}

$acc_total = drive_accumulator();
echo "acc=$acc_total\n";

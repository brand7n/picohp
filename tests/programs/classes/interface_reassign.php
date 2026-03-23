<?php

declare(strict_types=1);

interface Walkable
{
    public function step(): int;
}

class FastWalker implements Walkable
{
    public function step(): int
    {
        return 2;
    }
}

class SlowWalker implements Walkable
{
    public function step(): int
    {
        return 1;
    }
}

function totalSteps(Walkable $a, Walkable $b): int
{
    $current = $a;
    $sum = $current->step();
    $current = $b;
    $sum = $sum + $current->step();
    return $sum;
}

$f = new FastWalker();
$s = new SlowWalker();
echo totalSteps($f, $s);
echo "\n";

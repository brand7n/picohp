<?php

declare(strict_types=1);

class Point
{
    public int $x;
    public int $y;

    public function __construct(int $x, int $y)
    {
        $this->x = $x;
        $this->y = $y;
    }
}

function printPoint(Point $p): void
{
    echo $p->x;
    echo "\n";
    echo $p->y;
    echo "\n";
}

function addPoints(Point $a, Point $b): Point
{
    return new Point($a->x + $b->x, $a->y + $b->y);
}

$p1 = new Point(1, 2);
$p2 = new Point(3, 4);

printPoint($p1);

$p3 = addPoints($p1, $p2);
printPoint($p3);

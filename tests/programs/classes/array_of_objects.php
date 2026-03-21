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

/** @var array<int, Point> */
$points = [];
$points[] = new Point(1, 2);
$points[] = new Point(3, 4);
$points[] = new Point(5, 6);

foreach ($points as $p) {
    echo $p->x;
    echo "\n";
}

echo count($points);
echo "\n";

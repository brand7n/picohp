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

    public function sum(): int
    {
        return $this->x + $this->y;
    }
}

$p = new Point(3, 7);
echo $p->x;
echo "\n";
echo $p->y;
echo "\n";
echo $p->sum();
echo "\n";

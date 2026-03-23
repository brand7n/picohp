<?php

declare(strict_types=1);

interface Shape
{
    public function area(): int;
    public function name(): string;
}

class Square implements Shape
{
    private int $side;

    public function __construct(int $side)
    {
        $this->side = $side;
    }

    public function area(): int
    {
        return $this->side * $this->side;
    }

    public function name(): string
    {
        return "square";
    }
}

class Rect implements Shape
{
    private int $w;
    private int $h;

    public function __construct(int $w, int $h)
    {
        $this->w = $w;
        $this->h = $h;
    }

    public function area(): int
    {
        return $this->w * $this->h;
    }

    public function name(): string
    {
        return "rect";
    }
}

function describe(Shape $s): void
{
    echo $s->name() . ": " . strval($s->area()) . "\n";
}

$sq = new Square(5);
$rc = new Rect(3, 4);

describe($sq);
describe($rc);

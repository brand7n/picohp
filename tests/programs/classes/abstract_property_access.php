<?php

declare(strict_types=1);

abstract class Shape
{
    public string $color;

    public function __construct(string $color)
    {
        $this->color = $color;
    }

    abstract public function area(): int;
}

class Square extends Shape
{
    public int $side;

    public function __construct(string $color, int $side)
    {
        parent::__construct($color);
        $this->side = $side;
    }

    public function area(): int
    {
        return $this->side * $this->side;
    }
}

class Triangle extends Shape
{
    public int $base;
    public int $height;

    public function __construct(string $color, int $base, int $height)
    {
        parent::__construct($color);
        $this->base = $base;
        $this->height = $height;
    }

    public function area(): int
    {
        return ($this->base * $this->height) / 2;
    }
}

function printShape(Shape $s): void
{
    echo $s->color;
    echo "\n";
    echo $s->area();
    echo "\n";
}

/** @var Square $sq */
$sq = new Square("red", 5);
/** @var Triangle $tr */
$tr = new Triangle("blue", 6, 4);

printShape($sq);
printShape($tr);

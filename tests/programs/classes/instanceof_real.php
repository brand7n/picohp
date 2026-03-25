<?php

declare(strict_types=1);

interface Shape
{
    public function area(): int;
}

class Circle implements Shape
{
    public int $radius;

    public function __construct(int $radius)
    {
        $this->radius = $radius;
    }

    public function area(): int
    {
        return $this->radius * $this->radius * 3;
    }
}

class Square implements Shape
{
    public int $side;

    public function __construct(int $side)
    {
        $this->side = $side;
    }

    public function area(): int
    {
        return $this->side * $this->side;
    }
}

function describeShape(Shape $s): void
{
    if ($s instanceof Circle) {
        echo "circle\n";
    } elseif ($s instanceof Square) {
        echo "square\n";
    }
    echo $s->area();
    echo "\n";
}

/** @var Circle $c */
$c = new Circle(5);
/** @var Square $sq */
$sq = new Square(4);

describeShape($c);
describeShape($sq);

if ($c instanceof Shape) {
    echo "is shape\n";
}

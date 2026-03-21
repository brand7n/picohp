<?php

declare(strict_types=1);

class Shape
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function describe(): string
    {
        return $this->name;
    }
}

class Circle extends Shape
{
    public int $radius;

    public function __construct(int $radius)
    {
        $this->name = 'circle';
        $this->radius = $radius;
    }

    public function area(): int
    {
        return 3 * $this->radius * $this->radius;
    }
}

$s = new Shape('square');
echo $s->describe();
echo "\n";

$c = new Circle(5);
echo $c->describe();
echo "\n";
echo $c->area();
echo "\n";
echo $c->name;
echo "\n";

<?php

declare(strict_types=1);

abstract class Vehicle
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    abstract public function wheels(): int;

    public function describe(): string
    {
        return $this->name;
    }
}

class Car extends Vehicle
{
    public function __construct()
    {
        parent::__construct('car');
    }

    public function wheels(): int
    {
        return 4;
    }
}

class Bike extends Vehicle
{
    public function __construct()
    {
        parent::__construct('bike');
    }

    public function wheels(): int
    {
        return 2;
    }
}

$c = new Car();
echo $c->describe();
echo "\n";
echo $c->wheels();
echo "\n";

$b = new Bike();
echo $b->describe();
echo "\n";

<?php

declare(strict_types=1);

class Animal
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function speak(): string
    {
        return $this->name . ' says nothing';
    }
}

class Dog extends Animal
{
    public function __construct()
    {
        $this->name = 'dog';
    }

    public function speak(): string
    {
        return $this->name . ' says woof';
    }
}

class Cat extends Animal
{
    public function __construct()
    {
        $this->name = 'cat';
    }

    public function speak(): string
    {
        return $this->name . ' says meow';
    }
}

$a = new Animal('fish');
echo $a->speak();
echo "\n";

$d = new Dog();
echo $d->speak();
echo "\n";

$c = new Cat();
echo $c->speak();
echo "\n";

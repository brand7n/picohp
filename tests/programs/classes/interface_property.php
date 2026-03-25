<?php

declare(strict_types=1);

interface HasName
{
    public function getName(): string;
}

class Dog implements HasName
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class Cat implements HasName
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

function printName(HasName $animal): void
{
    echo $animal->name;
    echo "\n";
}

/** @var Dog $d */
$d = new Dog("Rex");
/** @var Cat $c */
$c = new Cat("Whiskers");

printName($d);
printName($c);

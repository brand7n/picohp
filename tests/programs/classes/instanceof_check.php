<?php

declare(strict_types=1);

class Animal
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

function checkAnimal(Animal $a): void
{
    assert($a instanceof Animal);
    echo $a->getName() . "\n";
}

$dog = new Animal("Rex");
checkAnimal($dog);

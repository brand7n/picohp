<?php

declare(strict_types=1);

trait HasName
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}

trait HasAge
{
    private int $age;

    public function getAge(): int
    {
        return $this->age;
    }

    public function setAge(int $age): void
    {
        $this->age = $age;
    }
}

class Person
{
    use HasName;
    use HasAge;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age = $age;
    }
}

$p = new Person("Alice", 30);
echo $p->getName() . "\n";
echo $p->getAge();
echo "\n";

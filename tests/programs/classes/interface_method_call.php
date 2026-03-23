<?php

declare(strict_types=1);

interface Nameable
{
    public function getName(): string;
}

class User implements Nameable
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

$u = new User("Alice");
echo $u->getName() . "\n";

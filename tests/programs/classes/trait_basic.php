<?php

declare(strict_types=1);

trait Greeter
{
    public function greet(string $name): string
    {
        return "Hello, " . $name;
    }
}

class MyClass
{
    use Greeter;
}

$obj = new MyClass();
echo $obj->greet("World") . "\n";

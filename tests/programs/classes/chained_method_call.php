<?php

declare(strict_types=1);

class Inner
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class Outer
{
    private Inner $inner;

    public function __construct(Inner $inner)
    {
        $this->inner = $inner;
    }

    public function getInner(): Inner
    {
        return $this->inner;
    }
}

$o = new Outer(new Inner("deep"));
echo $o->getInner()->getValue() . "\n";

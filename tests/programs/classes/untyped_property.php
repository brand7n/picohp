<?php

declare(strict_types=1);

class Container
{
    /** @var int */
    private $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }
}

$c = new Container(42);
echo $c->getValue();
echo "\n";

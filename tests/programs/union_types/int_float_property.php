<?php

declare(strict_types=1);

class NumberBox
{
    private int|float $value;

    public function __construct(int|float $value)
    {
        $this->value = $value;
    }

    public function getAsFloat(): float
    {
        return (float) $this->value;
    }
}

$a = new NumberBox(42);
$b = new NumberBox(3.14);

$v1 = $a->getAsFloat();
echo $v1;
echo "\n";
$v2 = $b->getAsFloat();
echo $v2;
echo "\n";

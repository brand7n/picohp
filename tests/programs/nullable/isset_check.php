<?php

declare(strict_types=1);

class Container
{
    private ?string $value;

    public function __construct(?string $value = null)
    {
        $this->value = $value;
    }

    public function hasValue(): bool
    {
        return isset($this->value);
    }

    public function getValue(): string
    {
        return $this->value ?? "empty";
    }
}

$a = new Container("hello");
$b = new Container();

if ($a->hasValue()) {
    echo $a->getValue() . "\n";
}

if (!$b->hasValue()) {
    echo "no value\n";
}

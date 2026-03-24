<?php

declare(strict_types=1);

class Parent_
{
    /** @var array<int, int> */
    protected array $items;

    public function __construct()
    {
        $this->items = [10, 20, 30];
    }

    public function getFirst(): int
    {
        return $this->items[0];
    }

    public function checkGreater(): bool
    {
        return $this->items[0] > 5;
    }
}

class Child_ extends Parent_
{
    protected array $items;

    public function __construct()
    {
        parent::__construct();
    }
}

/** @var Child_ $c */
$c = new Child_();
echo $c->getFirst();
echo "\n";
if ($c->checkGreater()) {
    echo "yes\n";
} else {
    echo "no\n";
}

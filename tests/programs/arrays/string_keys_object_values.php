<?php

declare(strict_types=1);

class Item
{
    private string $name;
    private int $price;

    public function __construct(string $name, int $price)
    {
        $this->name = $name;
        $this->price = $price;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}

/** @var array<string, Item> $items */
$items = ['apple' => new Item('Apple', 100), 'banana' => new Item('Banana', 50)];

echo $items['apple']->getName();
echo "\n";
echo $items['apple']->getPrice();
echo "\n";
echo $items['banana']->getName();
echo "\n";
echo $items['banana']->getPrice();
echo "\n";

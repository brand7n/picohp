<?php

declare(strict_types=1);

class Bag
{
    /** @var array<int, string> */
    public array $items;

    public function __construct()
    {
        $this->items = [];
    }

    public function add(string $item): void
    {
        $this->items[] = $item;
    }

    public function count(): int
    {
        return count($this->items);
    }
}

$bag = new Bag();
$bag->add('apple');
$bag->add('banana');
$bag->add('cherry');

echo $bag->count();
echo "\n";

foreach ($bag->items as $item) {
    echo $item;
    echo "\n";
}

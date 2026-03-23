<?php

declare(strict_types=1);

class Collection
{
    /** @var array<int> */
    private $items;

    public function __construct()
    {
        $this->items = [];
    }

    public function add(int $item): void
    {
        $this->items[] = $item;
    }

    public function count(): int
    {
        return count($this->items);
    }
}

$c = new Collection();
$c->add(1);
$c->add(2);
$c->add(3);
echo $c->count();
echo "\n";

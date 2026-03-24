<?php

declare(strict_types=1);

class Item
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

class Container
{
    /** @var mixed */
    private $data;

    public function set(mixed $value): void
    {
        $this->data = $value;
    }

    /** @return mixed */
    public function get(): mixed
    {
        return $this->data;
    }
}

// Store and retrieve a string through mixed
$c = new Container();
$c->set("hello");
/** @var string $s */
$s = $c->get();
echo $s . "\n";

// Store and retrieve an int through mixed
$c->set(42);
/** @var int $n */
$n = $c->get();
echo $n;
echo "\n";

// Store an object, retrieve and call method on it
$c->set(new Item("widget"));
/** @var Item $item */
$item = $c->get();
echo $item->getName() . "\n";

<?php

declare(strict_types=1);

trait Counter
{
    private int $count;

    public function increment(): void
    {
        $this->count = $this->count + 1;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}

class MyCounter
{
    use Counter;

    public function __construct()
    {
        $this->count = 0;
    }
}

$c = new MyCounter();
$c->increment();
$c->increment();
$c->increment();
echo $c->getCount();
echo "\n";

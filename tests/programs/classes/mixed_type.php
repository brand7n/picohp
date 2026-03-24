<?php

declare(strict_types=1);

class ValueHolder
{
    /** @var mixed */
    private $data;

    public function setString(string $s): void
    {
        $this->data = $s;
    }

    public function setInt(int $n): void
    {
        $this->data = $n;
    }

    /** @var array<int, int> $arr */
    public function setArray(array $arr): void
    {
        $this->data = $arr;
    }
}

$h = new ValueHolder();
$h->setString("hello");
$h->setInt(42);

/** @var array<int, int> $nums */
$nums = [1, 2, 3];
$h->setArray($nums);

echo "ok\n";

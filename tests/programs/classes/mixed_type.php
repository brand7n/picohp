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

    public function getString(): string
    {
        /** @var string $result */
        $result = $this->data;
        return $result;
    }

    public function setInt(int $n): void
    {
        $this->data = $n;
    }

    public function getInt(): int
    {
        /** @var int $result */
        $result = $this->data;
        return $result;
    }
}

$h = new ValueHolder();

$h->setString("hello");
echo $h->getString() . "\n";

$h->setInt(42);
echo $h->getInt();
echo "\n";

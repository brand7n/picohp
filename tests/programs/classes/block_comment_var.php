<?php

declare(strict_types=1);

// Block (non-doc) comments before a property are not T_DOC_COMMENT; @var must still be honored.
class Box
{
    /* @var int */
    private $n;

    public function __construct(int $n)
    {
        $this->n = $n;
    }

    public function get(): int
    {
        return $this->n;
    }
}

$b = new Box(7);
echo $b->get();
echo "\n";

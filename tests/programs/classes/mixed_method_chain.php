<?php

declare(strict_types=1);

class Wrapper
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class Box
{
    /** @var mixed */
    private $content;

    public function put(mixed $item): void
    {
        $this->content = $item;
    }

    /** @return mixed */
    public function take(): mixed
    {
        return $this->content;
    }
}

$box = new Box();
$box->put(new Wrapper("inside"));

/** @var Wrapper $w */
$w = $box->take();
echo $w->getValue() . "\n";

// Mixed property access
$box->put("direct");
/** @var string $s */
$s = $box->take();
echo $s . "\n";

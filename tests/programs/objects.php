<?php

declare(strict_types=1);

enum Color
{
    case Red;
    case Blue;
}

final class Counter
{
    public function __construct(
        private int $count,
        private string $label,
    ) {}

    public function bump(int $by): int
    {
        $this->count = $this->count + $by;
        return $this->count;
    }

    public function label(): string
    {
        return $this->label;
    }
}

$c = new Counter(5, "hit");
echo $c->bump(3), " ", $c->bump(4), " ", $c->label(), "\n";

$color = Color::Red;
echo match ($color) {
    Color::Red => "red",
    Color::Blue => "blue",
}, "\n";

<?php

declare(strict_types=1);

class Counter
{
    private static int $next = 0;

    public static function create(): int
    {
        $val = self::getNext();
        return $val;
    }

    public static function getNext(): int
    {
        Counter::$next = Counter::$next + 1;
        return Counter::$next;
    }
}

echo Counter::create();
echo "\n";
echo Counter::create();
echo "\n";
echo Counter::create();
echo "\n";

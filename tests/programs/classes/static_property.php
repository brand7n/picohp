<?php

declare(strict_types=1);

class Counter
{
    public static int $count = 0;

    public static function next(): int
    {
        $val = self::$count;
        self::$count = self::$count + 1;
        return $val;
    }
}

echo Counter::next();
echo "\n";
echo Counter::next();
echo "\n";
echo Counter::next();
echo "\n";
echo Counter::$count;
echo "\n";

<?php

declare(strict_types=1);

class Counter
{
    public static int $count = 0;
    public static int $step = 2;

    public static function increment(): void
    {
        Counter::$count = Counter::$count + Counter::$step;
    }
}

Counter::increment();
Counter::increment();
Counter::increment();
echo Counter::$count;
echo "\n";

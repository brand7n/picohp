<?php

/** @var int */
$glob = 5;

class Test
{
    public static int $value = 0;
    public static function test1(): int
    {
        echo start();
        return start();
    }
}

function start(): int
{
    /** @var int */
    $a = 4 * 5 + 3;
    return $a;
}
echo $glob;
echo Test::test1();

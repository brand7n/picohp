<?php

/** @var int */
$glob = 5;

class Test
{
    public static int $value = 0;
    public static function test1(): int
    {
        /** @var string */
        $a[5] = 4;
        //echo start();
        echo $a[5];
        //return start();
        return 1;
    }
}

function start(): int
{
    /** @var int */
    $a = 1234 * 5 + 0xf3;
    return $a;
}
echo $glob;
echo Test::test1();

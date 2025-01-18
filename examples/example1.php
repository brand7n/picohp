<?php

/** @var int */
$glob = 5;

class Test
{
    public static int $value = 0;
    public static function test1(): int
    {
        {
            echo 1234 << 1;
        }
        /** @var int */
        $c = (int)(1 < 2) / (int)(int)(2 > 1) - (int)(1 == 1);
        echo "Hello: {$c}";
        echo(1.234);
        echo((float)(float)(1 >> 2));
        /** @var string */
        $a[5] = -4;
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

if ($glob > 0) {
    start();
} else {
    Test::test1();
}

if (Test::$value > 0) {
    start();
} else {
    Test::test1();
}

echo $glob;
echo Test::test1();

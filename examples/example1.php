<?php

/** @var int */
$glob = 5;

class Test
{
    public static int $value = 0;
    public static function test1(bool $b, float $f): int
    {
        {
            echo 1234 << 1;
            /** @var int */
            $a = 1;
            echo $a;
            /** @var float */
            $b = 1.234;
            /** @var bool */
            $c = true;
        }
        /** @var int */
        $c = (int)(1 < 2) / (int)(int)(2 > 1) - (int)($b) + (int)($f);
        echo "Hello: {$c}";
        echo(1.234);
        echo((float)(float)(1 >> 2));
        /** @var string */
        $a[5] = -4;
        echo $a[5];
        return 1;
    }
}

function start(int $a, int $b): int
{
    while ($a > 0) {
        $a = $a - 1;
        echo $a;
    }
    $a = 1234 * 5 + 0xf3;
    return $a;
}

if ($glob > 0) {
    start(100, 200);
} else {
    Test::test1();
}

if (Test::$value == 0) {
    start(200, 100);
} else {
    Test::test1(true, 1.234);
}

echo $glob;
echo Test::test1(false, 0.5);

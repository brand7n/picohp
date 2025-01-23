<?php

namespace Brandin\TestProj;

class Test implements BlahInterface
{
    public static function test1(bool $b, float $f): int
    {
        $c = (int)($b) + (int)($f);// + self::blah(5);
        echo $c;
        return $c;
    }

    public static function blah(int $a): float
    {
        return 1.234;
    }
}

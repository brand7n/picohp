<?php

class Test
{
    //public static int $value = 0;
    public static function test1(): int
    {
        return 1;
    }
}

function main(): int
{
    /** @var int */
    $a = 4 * 5 + 3;
    return Test::test1();
}

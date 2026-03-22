<?php

function main(): int
{
    // picoHP will ignore this but we will keep it in for PHP compatibility
    require_once __DIR__ . '/vendor/autoload.php';
    return Test_test1(true, 1.234);
    return 0;
}
function Test_test1(bool $b, float $f): int
{
    $c = (int) $b + (int) $f;
    // + self::blah(5);
    echo $c;
    return $c;
}
function Test_blah(int $a): float
{
    return 1.234;
}
namespace Brandin\TestProj;

interface BlahInterface
{
    public static function blah(int $a): float;
}
namespace Brandin\TestProj;

class Test implements BlahInterface
{
}
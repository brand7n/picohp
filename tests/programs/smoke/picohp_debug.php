<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../picohp_stubs.php';

function testDebug(int $x, string $s, bool $b, float $f): void
{
    picohp_debug($x);
    picohp_debug($s);
    picohp_debug($b);
    picohp_debug($f);
}

$n = 42;
picohp_debug($n);

$str = 'hello';
picohp_debug($str);

testDebug(99, 'world', true, 3.14);

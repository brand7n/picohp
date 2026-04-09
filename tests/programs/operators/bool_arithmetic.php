<?php

declare(strict_types=1);

function countTrue(bool $a, bool $b, bool $c): int
{
    return ($a ? 1 : 0) + ($b ? 1 : 0) + ($c ? 1 : 0);
}

echo countTrue(true, true, false) . "\n";
echo countTrue(false, false, false) . "\n";
echo countTrue(true, true, true) . "\n";

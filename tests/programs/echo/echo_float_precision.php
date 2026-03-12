<?php

function test_precision(): int
{
    /** @var float */
    $a = 0.1;
    /** @var float */
    $b = 0.2;
    echo (float)((int)$a + (int)$b);
    echo 1234.56789;
    echo 0.123456789012345;
    return 0;
}

test_precision();

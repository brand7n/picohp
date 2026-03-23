<?php

declare(strict_types=1);

function testCasts(): void
{
    $i = 42;
    $f = 3.14;

    $fi = (float) $i;
    echo $fi;
    echo "\n";

    $if = (int) $f;
    echo $if;
    echo "\n";

    $b = (bool) $i;
    if ($b) { /** @phpstan-ignore if.alwaysTrue */
        echo "true\n";
    }

    $b0 = (bool) 0;
    if ($b0) { /** @phpstan-ignore if.alwaysFalse */
        echo "should not print\n";
    } else {
        echo "false\n";
    }
}

testCasts();

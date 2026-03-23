<?php

declare(strict_types=1);

function checkResult(int $index): void
{
    /** @phpstan-ignore-next-line */
    if ($index !== false) {
        echo "found at " . strval($index) . "\n";
    } else {
        echo "not found\n";
    }
}

checkResult(2);
checkResult(0);

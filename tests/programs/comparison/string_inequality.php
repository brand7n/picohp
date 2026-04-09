<?php

declare(strict_types=1);

function check(string $a, string $b): void
{
    if ($a !== $b) {
        echo "different\n";
    } else {
        echo "same\n";
    }
}

check('foo', 'bar');
check('hello', 'hello');
check('abc', 'abd');

<?php

declare(strict_types=1);

function describe(?string $val): string
{
    if ($val === null) {
        return 'nothing';
    }
    return $val;
}

echo describe('something');
echo "\n";
echo describe(null);
echo "\n";

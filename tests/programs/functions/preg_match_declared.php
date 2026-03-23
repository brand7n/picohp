<?php

declare(strict_types=1);

function parseGeneric(string $type): string
{
    /** @var array<int, string> $m */
    $m = [];
    if (preg_match('/^map<(\w+),\s*(\w+)>$/', $type, $m) === 1) {
        return $m[1] . "=>" . $m[2];
    }
    return $type;
}

echo parseGeneric("map<string, int>") . "\n";
echo parseGeneric("plain") . "\n";

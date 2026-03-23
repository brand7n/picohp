<?php

declare(strict_types=1);

function testBoolCastFloat(float $f): bool
{
    return (bool) $f;
}

function testStringCastString(string $s): string
{
    /** @phpstan-ignore-next-line */
    return (string) $s;
}

function testStrvalFloat(float $f): string
{
    return strval($f);
}

if (testBoolCastFloat(1.5)) {
    echo "true\n";
}
if (!testBoolCastFloat(0.0)) {
    echo "false\n";
}

echo testStringCastString("hello") . "\n";
echo testStrvalFloat(2.5) . "\n";

<?php

declare(strict_types=1);

function describeNumber(int $n): string
{
    $result = "";
    switch ($n) {
        case 1:
            $result = "one";
            break;
        case 2:
            $result = "two";
            break;
        case 3:
            $result = "three";
            break;
        default:
            $result = "other";
            break;
    }
    return $result;
}

echo describeNumber(1) . "\n";
echo describeNumber(2) . "\n";
echo describeNumber(3) . "\n";
echo describeNumber(99) . "\n";

<?php

declare(strict_types=1);

function classify(int $n): string
{
    $result = "";
    switch ($n) {
        case 1:
        case 2:
        case 3:
            $result = "small";
            break;
        case 4:
        case 5:
            $result = "medium";
            break;
        default:
            $result = "large";
            break;
    }
    return $result;
}

echo classify(1) . "\n";
echo classify(2) . "\n";
echo classify(3) . "\n";
echo classify(4) . "\n";
echo classify(5) . "\n";
echo classify(10) . "\n";

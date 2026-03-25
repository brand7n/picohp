<?php

declare(strict_types=1);

class Defaults
{
    public static float $f = 1.5;
    public static bool $t = true;
    public static bool $fl = false;
    public static int $i = 2;
}

echo Defaults::$f;
echo "\n";

if (Defaults::$t) {
    echo "t-true\n";
} else {
    echo "t-false\n";
}

if (Defaults::$fl) {
    echo "fl-true\n";
} else {
    echo "fl-false\n";
}

echo Defaults::$i;
echo "\n";

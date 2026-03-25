<?php

declare(strict_types=1);

/** @var int $i */
$i = 42;
/** @var string $s */
$s = "hello";
/** @var float $f */
$f = 3.14;
/** @var bool $b */
$b = true;

/** @phpstan-ignore-next-line */
if (is_int($i)) {
    echo "int:yes\n";
}
/** @phpstan-ignore-next-line */
if (!is_string($i)) {
    echo "int:not_string\n";
}

/** @phpstan-ignore-next-line */
if (is_string($s)) {
    echo "string:yes\n";
}
/** @phpstan-ignore-next-line */
if (!is_int($s)) {
    echo "string:not_int\n";
}

/** @phpstan-ignore-next-line */
if (is_float($f)) {
    echo "float:yes\n";
}

/** @phpstan-ignore-next-line */
if (is_bool($b)) {
    echo "bool:yes\n";
}

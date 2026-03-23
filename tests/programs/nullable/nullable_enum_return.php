<?php

declare(strict_types=1);

function maybeNull(bool $returnNull): ?string
{
    if ($returnNull) {
        return null;
    }
    return "hello";
}

$a = maybeNull(false);
if ($a !== null) {
    echo $a . "\n";
}

$b = maybeNull(true);
if ($b === null) {
    echo "got null\n";
}

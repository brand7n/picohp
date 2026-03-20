<?php

declare(strict_types=1);

function maybeNull(bool $flag): ?string
{
    if ($flag) {
        return 'yes';
    }
    return null;
}

$a = maybeNull(true);
$b = maybeNull(false);
echo $a ?? 'none';
echo "\n";
echo $b ?? 'none';
echo "\n";

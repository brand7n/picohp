<?php

declare(strict_types=1);

function buildMessage(int $count): string
{
    $msg = 'count: ';
    $msg .= $count;
    return $msg;
}

echo buildMessage(42) . "\n";

$s = '';
$s .= 100;
$s .= ' items';
echo $s . "\n";

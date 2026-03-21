<?php

declare(strict_types=1);

enum Color: string
{
    case RED = 'red';
    case GREEN = 'green';
    case BLUE = 'blue';
}

function printColor(Color $c): void
{
    echo $c->value;
    echo "\n";
}

$r = Color::RED;
$g = Color::GREEN;

printColor($r);
printColor($g);
printColor(Color::BLUE);

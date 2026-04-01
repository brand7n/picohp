<?php

declare(strict_types=1);

enum Color: string
{
    case Red = "red";
    case Green = "green";
    case Blue = "blue";
}

/** @var Color $c */
$c = Color::tryFrom("green");
echo $c->value;
echo "\n";

/** @var Color $c2 */
$c2 = Color::from("blue");
echo $c2->value;
echo "\n";

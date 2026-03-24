<?php

declare(strict_types=1);

interface Printable
{
    public function display(): void;
}

class Base
{
    public function hello(): string
    {
        return "base";
    }
}

class Child extends Base implements Printable
{
    public function display(): void
    {
        echo $this->hello() . "\n";
    }
}

function acceptBase(Base $b): void
{
    echo $b->hello() . "\n";
}

function acceptPrintable(Printable $p): void
{
    $p->display();
}

$child = new Child();

// Assign child to parent-typed variable
$b = $child;
acceptBase($b);

// Assign child to interface-typed variable
$p = $child;
acceptPrintable($p);

// Reassign parent-typed var to different child
$b2 = new Base();
$b2 = $child;
acceptBase($b2);

<?php

declare(strict_types=1);

class Marker
{
    public function tag(): string
    {
        return "marked";
    }
}

$m = new Marker();
echo $m->tag() . "\n";

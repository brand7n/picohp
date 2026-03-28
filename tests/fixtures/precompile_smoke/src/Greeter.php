<?php

declare(strict_types=1);

namespace SmokeFixture;

final class Greeter
{
    public function line(): string
    {
        $t = new Token();
        return $t->label() . "\n";
    }
}

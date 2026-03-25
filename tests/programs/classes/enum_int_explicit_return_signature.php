<?php

declare(strict_types=1);

enum StrEnum: string
{
    case A = 'abc';

    public function m(int $x): int
    {
        // Not called by the program: picoHP currently doesn't emit IR for enum method bodies.
        // This program only exercises semantic registration + `$enumCase->value` lowering.
        $unused = $x;
        return 0;
    }
}

$e = StrEnum::A;
echo $e->value;
echo "\n";

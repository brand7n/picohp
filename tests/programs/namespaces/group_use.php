<?php

declare(strict_types=1);

namespace N\Sub;

final class Thing
{
    public static function out(): string
    {
        return 'ok';
    }
}

namespace N;

use N\Sub\{Thing};

echo Thing::out();
echo "\n";

<?php

declare(strict_types=1);

/** @var int $argc */
/** @var list<string> $argv */

echo "argc=" . $argc . "\n";

$len = strlen($argv[0]);
if ($len > 0) {
    echo "argv0_nonempty\n";
}

echo "done\n";

<?php

declare(strict_types=1);

/** @var int $argc */
/** @var list<string> $argv */

/** @var list<string> $rest */
$rest = array_slice($argv, 1, $argc - 1);
$count = count($rest);
echo "rest_count=" . $count . "\n";
echo "done\n";

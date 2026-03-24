<?php

declare(strict_types=1);

/** @var array<int, string> $parts */
$parts = ['hello', 'world', 'test'];

echo implode(', ', $parts) . "\n";
echo implode('-', $parts) . "\n";

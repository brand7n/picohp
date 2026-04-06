<?php

declare(strict_types=1);

function mayFail(): void
{
    throw new RuntimeException('runtime error');
}

try {
    mayFail();
} catch (\Throwable $e) {
    echo "Caught Throwable: " . $e->getMessage() . "\n";
}

try {
    throw new InvalidArgumentException('invalid');
} catch (\Throwable $e) {
    echo "Caught Throwable: " . $e->getMessage() . "\n";
}

echo "done\n";

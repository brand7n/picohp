<?php

declare(strict_types=1);

function inner(): void
{
    throw new RuntimeException('inner error');
}

function outer(): void
{
    try {
        inner();
    } catch (RuntimeException $e) {
        throw $e;
    }
}

try {
    outer();
} catch (RuntimeException $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "done\n";

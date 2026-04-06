<?php

declare(strict_types=1);

function riskyOperation(): void
{
    throw new RuntimeException('something went wrong');
}

try {
    riskyOperation();
} catch (RuntimeException $e) {
    echo "RuntimeException: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

try {
    throw new InvalidArgumentException('bad arg');
} catch (LogicException $e) {
    echo "LogicException: " . $e->getMessage() . "\n";
}

echo "done\n";

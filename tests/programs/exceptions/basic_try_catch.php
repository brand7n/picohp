<?php

declare(strict_types=1);

class MyException extends Exception
{
}

function riskyOperation(int $x): string
{
    if ($x === 0) {
        throw new MyException('division by zero');
    }
    return "result: " . strval($x);
}

try {
    echo riskyOperation(5) . "\n";
} catch (MyException $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

try {
    echo riskyOperation(0) . "\n";
} catch (MyException $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "done\n";

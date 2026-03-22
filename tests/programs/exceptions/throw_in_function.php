<?php

declare(strict_types=1);

class MyException extends Exception
{
}

function inner(): string
{
    throw new MyException('from inner');
}

function outer(): string
{
    return inner() . " wrapped";
}

try {
    echo outer() . "\n";
} catch (MyException $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "after\n";

<?php

declare(strict_types=1);

class MyException extends Exception
{
}

function maybeThrow(bool $doThrow): string
{
    if ($doThrow) {
        throw new MyException('oops');
    }
    return "ok";
}

try {
    echo maybeThrow(false) . "\n";
} catch (MyException $e) {
    echo "catch\n";
} finally {
    echo "finally\n";
}

try {
    echo maybeThrow(true) . "\n";
} catch (MyException $e) {
    echo "caught: " . $e->getMessage() . "\n";
} finally {
    echo "cleanup\n";
}

echo "done\n";

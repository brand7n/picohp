<?php

declare(strict_types=1);

class MyException extends Exception
{
}

class ChildException extends MyException
{
}

function throwBase(): void
{
    throw new MyException('base error');
}

function throwChild(): void
{
    throw new ChildException('child error');
}

try {
    throwChild();
} catch (ChildException $e) {
    echo "ChildException: " . $e->getMessage() . "\n";
} catch (MyException $e) {
    echo "MyException: " . $e->getMessage() . "\n";
}

try {
    throwBase();
} catch (ChildException $e) {
    echo "ChildException: " . $e->getMessage() . "\n";
} catch (MyException $e) {
    echo "MyException: " . $e->getMessage() . "\n";
}

echo "done\n";

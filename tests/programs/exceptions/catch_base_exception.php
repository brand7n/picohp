<?php

declare(strict_types=1);

class MyException extends Exception
{
}

try {
    throw new MyException('base caught');
} catch (Exception $e) {
    echo 'caught: ' . $e->getMessage() . "\n";
}

echo "done\n";

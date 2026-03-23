<?php

declare(strict_types=1);

interface Logger
{
    public function log(string $msg): void;
}

class ConsoleLogger implements Logger
{
    public function log(string $msg): void
    {
        echo $msg . "\n";
    }
}

function doLog(Logger $logger, string $msg): void
{
    $logger->log($msg);
}

$l = new ConsoleLogger();
doLog($l, "hello");
doLog($l, "world");

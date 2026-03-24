<?php

declare(strict_types=1);

class Config
{
    public const MAX_SIZE = 100;
    public const MIN_SIZE = 10;
    public const OFFSET = 5;

    public function getRange(): int
    {
        return Config::MAX_SIZE - Config::MIN_SIZE + Config::OFFSET;
    }
}

/** @var Config $c */
$c = new Config();
echo $c->getRange();
echo "\n";

/** @var int $total */
$total = Config::MAX_SIZE + Config::MIN_SIZE;
echo $total;
echo "\n";

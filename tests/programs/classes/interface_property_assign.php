<?php

declare(strict_types=1);

interface HasScore
{
    public function getScore(): int;
}

class Player implements HasScore
{
    public int $score;

    public function __construct(int $score)
    {
        $this->score = $score;
    }

    public function getScore(): int
    {
        return $this->score;
    }
}

class Bot implements HasScore
{
    public int $score;

    public function __construct(int $score)
    {
        $this->score = $score;
    }

    public function getScore(): int
    {
        return $this->score;
    }
}

function addPoints(HasScore $entity, int $points): void
{
    $entity->score = $entity->score + $points;
}

/** @var Player $p */
$p = new Player(10);
/** @var Bot $b */
$b = new Bot(20);

addPoints($p, 5);
addPoints($b, 3);

echo $p->score;
echo "\n";
echo $b->score;
echo "\n";

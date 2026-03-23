<?php

declare(strict_types=1);

class Color
{
    private int $r;
    private int $g;
    private int $b;

    public function __construct(int $r, int $g, int $b)
    {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
    }

    public static function red(): Color
    {
        return new self(255, 0, 0);
    }

    public static function fromGray(int $v): Color
    {
        return self::fromRGB($v, $v, $v);
    }

    public static function fromRGB(int $r, int $g, int $b): Color
    {
        return new Color($r, $g, $b);
    }

    public function getR(): int
    {
        return $this->r;
    }

    public function getG(): int
    {
        return $this->g;
    }
}

$red = Color::red();
echo $red->getR();
echo "\n";
echo $red->getG();
echo "\n";

$gray = Color::fromGray(128);
echo $gray->getR();
echo "\n";
echo $gray->getG();
echo "\n";

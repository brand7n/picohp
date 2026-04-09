<?php

declare(strict_types=1);

class Config
{
    private float $ratio = 1.5;
    private int $offset = -10;
    private bool $enabled;
    private ?string $label;

    public function __construct(bool $enabled = true, ?string $label = null)
    {
        $this->enabled = $enabled;
        $this->label = $label;
    }

    public function getRatio(): float
    {
        return $this->ratio;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }
}

$c = new Config();
echo $c->getRatio();
echo "\n";
echo $c->getOffset() . "\n";
echo ($c->isEnabled() ? 'yes' : 'no') . "\n";
echo ($c->getLabel() ?? 'none') . "\n";

$d = new Config(false);
echo ($d->isEnabled() ? 'yes' : 'no') . "\n";

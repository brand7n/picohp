<?php

declare(strict_types=1);

namespace App\PicoHP\Tree;

interface NodeInterface
{
    // Set the string value
    public function setValue(string $value): void;

    // Get the string value
    public function getValue(): string;

    public function getParent(): ?NodeInterface;

    public function setParent(?NodeInterface $node): void;
}
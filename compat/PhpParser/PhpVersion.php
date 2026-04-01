<?php

declare(strict_types=1);

namespace PhpParser;

/**
 * Stub for self-compilation: only getNewestSupported() and fromComponents().
 * fromString() uses preg_match which picoHP doesn't fully support in vendor code.
 */
class PhpVersion
{
    private int $id;

    private function __construct(int $id)
    {
        $this->id = $id;
    }

    public static function fromComponents(int $major, int $minor): self
    {
        return new self($major * 10000 + $minor * 100);
    }

    public static function getNewestSupported(): self
    {
        return self::fromComponents(8, 5);
    }

    public function equals(PhpVersion $other): bool
    {
        return $this->id === $other->id;
    }
}

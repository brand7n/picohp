<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * A builtin class definition parsed from a header file (e.g. Exception hierarchy).
 */
final class BuiltinClassDef
{
    /**
     * @param array<string, PicoType> $properties property name → type
     * @param array<string, BuiltinMethodDef> $methods method name → definition
     * @param list<string> $interfaces interface names this class implements
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $parentName,
        public readonly array $properties,
        public readonly array $methods,
        public readonly bool $isInterface = false,
        public readonly array $interfaces = [],
    ) {
    }
}

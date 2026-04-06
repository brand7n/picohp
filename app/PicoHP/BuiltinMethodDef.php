<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * A method on a builtin class, parsed from a header file.
 */
final class BuiltinMethodDef
{
    /**
     * @param list<array{name: string, type: PicoType}> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly PicoType $returnType,
        public readonly array $params,
    ) {
    }
}

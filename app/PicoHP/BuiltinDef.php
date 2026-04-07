<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * A single builtin function definition parsed from a header file.
 */
final class BuiltinDef
{
    public readonly int $requiredCount;

    /**
     * @param list<array{name: string, type: PicoType, hasDefault: bool, defaultValue: int|float|string|null}> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly PicoType $returnType,
        public readonly array $params,
        public readonly ?string $runtimeSymbol,
        public readonly ?string $intrinsic,
        public readonly ?int $returnMatchesArg,
        public readonly ?int $returnElementType,
        int $requiredCount = -1,
    ) {
        // When requiredCount is not provided, assume all params are required.
        // BuiltinRegistry always passes the correct value.
        $this->requiredCount = $requiredCount >= 0 ? $requiredCount : count($params);
    }

    public function paramCount(): int
    {
        return count($this->params);
    }

    public function requiredParamCount(): int
    {
        return $this->requiredCount;
    }

    public function returnBaseType(): BaseType
    {
        return $this->returnType->toBase();
    }
}

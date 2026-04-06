<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * A single builtin function definition parsed from a header file.
 */
final class BuiltinDef
{
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
    ) {
    }

    public function paramCount(): int
    {
        return count($this->params);
    }

    public function requiredParamCount(): int
    {
        $count = 0;
        foreach ($this->params as $param) {
            if (!$param['hasDefault']) {
                $count++;
            }
        }

        return $count;
    }

    public function returnBaseType(): BaseType
    {
        return $this->returnType->toBase();
    }
}

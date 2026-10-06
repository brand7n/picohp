<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use Remodulate\Pico\{Collection, PicoString, Value};

/**
 * Thin wrapper over one php-parser JSON AST node, backed by the pico
 * runtime (Json::parse → Value tree of PicoString/Collection).
 *
 * php-parser's JSON format: {"nodeType": "Stmt_Function", "attributes": {...}, ...fields}
 * Type info resolved externally (PHPStan) arrives as an attribute:
 *   "attributes": {"resolvedType": "int", "resolvedReturnType": "void"}
 *
 * The runtime is a dual implementation (PHP classes under `php`, Rust FFI
 * in compiled binaries) so this code is identical in dev and self-hosted.
 */
final class AstNode
{
    public function __construct(
        public readonly Value $data,
    ) {}

    /** @return Collection<Value> */
    private function map(): Collection
    {
        return $this->data->asMap();
    }

    private function has(string $key): bool
    {
        return $this->map()->has($key);
    }

    private function get(string $key): ?Value
    {
        if (!$this->has($key)) {
            return null;
        }
        $v = $this->map()->get($key);
        return $v->isNull() ? null : $v;
    }

    public function type(): string
    {
        $v = $this->get('nodeType');
        if ($v === null || $v->tag() !== Value::STRING) {
            throw new \RuntimeException('AST node missing nodeType');
        }
        return $v->asString()->raw();
    }

    /** @return list<AstNode> */
    public function nodes(string $key): array
    {
        $v = $this->get($key);
        if ($v === null || $v->tag() !== Value::ARRAY) {
            return [];
        }
        $col = $v->asArray();
        $result = [];
        for ($i = 0; $i < $col->count(); $i++) {
            $item = $col->getAt($i);
            if ($item->tag() === Value::MAP) {
                $result[] = new self($item);
            }
        }
        return $result;
    }

    public function node(string $key): ?self
    {
        $v = $this->get($key);
        return ($v !== null && $v->tag() === Value::MAP) ? new self($v) : null;
    }

    public function name(): string
    {
        $v = $this->get('name');
        if ($v !== null && $v->tag() === Value::STRING) {
            return $v->asString()->raw();
        }
        // Identifier node: {"nodeType": "Identifier", "name": "foo"}
        if ($v !== null && $v->tag() === Value::MAP) {
            $inner = (new self($v))->get('name');
            if ($inner !== null && $inner->tag() === Value::STRING) {
                return $inner->asString()->raw();
            }
        }
        throw new \RuntimeException("Cannot resolve name for node {$this->type()}");
    }

    public function string(string $key): string
    {
        $v = $this->get($key);
        return ($v !== null && $v->tag() === Value::STRING) ? $v->asString()->raw() : '';
    }

    public function int(string $key): int
    {
        $v = $this->get($key);
        return $v === null ? 0 : $v->asInt();
    }

    public function float(string $key): float
    {
        $v = $this->get($key);
        return $v === null ? 0.0 : $v->asFloat();
    }

    /** PHPStan-resolved type attribute, e.g. "int", "string|null". Null if not stamped. */
    public function resolvedType(): ?string
    {
        $attrs = $this->node('attributes');
        if ($attrs === null) {
            return null;
        }
        $t = $attrs->get('resolvedType');
        return ($t !== null && $t->tag() === Value::STRING) ? $t->asString()->raw() : null;
    }

    public function line(): int
    {
        $attrs = $this->node('attributes');
        if ($attrs === null) {
            return 0;
        }
        $l = $attrs->get('startLine');
        return $l !== null ? $l->asInt() : 0;
    }
}

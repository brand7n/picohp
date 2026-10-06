<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

/**
 * Thin wrapper over one php-parser JSON AST node.
 *
 * php-parser's JSON format: {"nodeType": "Stmt_Function", "attributes": {...}, ...fields}
 * Type info resolved externally (PHPStan) arrives as an attribute:
 *   "attributes": {"resolvedType": "int", "resolvedReturnType": "void"}
 */
final class AstNode
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new self($decoded);
    }

    public function type(): string
    {
        $type = $this->data['nodeType'] ?? null;
        if (!is_string($type)) {
            throw new \RuntimeException('AST node missing nodeType: ' . json_encode($this->data));
        }
        return $type;
    }

    /** @return list<AstNode> */
    public function nodes(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $result[] = new self($item);
            }
        }
        return $result;
    }

    public function node(string $key): ?self
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? new self($value) : null;
    }

    public function name(): string
    {
        $name = $this->data['name'] ?? null;
        if (is_string($name)) {
            return $name;
        }
        if (is_array($name) && isset($name['name']) && is_string($name['name'])) {
            return $name['name']; // Identifier node
        }
        // php-parser JSON: {"name": {"nodeType": "Identifier", "name": "foo"}}
        if (is_array($name) && isset($name['name'])) {
            return (string) $name['name'];
        }
        throw new \RuntimeException("Cannot resolve name for node {$this->type()}");
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        return is_int($value) ? $value : (int) $value;
    }

    public function float(string $key): float
    {
        $value = $this->data[$key] ?? null;
        return is_float($value) ? $value : (float) $value;
    }

    /** PHPStan-resolved type attribute, e.g. "int", "string|null". Null if not stamped. */
    public function resolvedType(): ?string
    {
        $attrs = $this->data['attributes'] ?? null;
        if (!is_array($attrs)) {
            return null;
        }
        $type = $attrs['resolvedType'] ?? null;
        return is_string($type) ? $type : null;
    }

    public function line(): int
    {
        $attrs = $this->data['attributes'] ?? null;
        if (is_array($attrs) && isset($attrs['startLine']) && is_int($attrs['startLine'])) {
            return $attrs['startLine'];
        }
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

class EnumMetadata
{
    public string $name;
    public ?string $backingType;

    /** @var array<string, int> case name => tag index */
    public array $cases = [];

    /** @var array<string, string|int> case name => backing value */
    public array $backingValues = [];

    public function __construct(string $name, ?string $backingType = null)
    {
        $this->name = $name;
        $this->backingType = $backingType;
    }

    public function addCase(string $name, string|int|null $backingValue = null): int
    {
        $tag = count($this->cases);
        $this->cases[$name] = $tag;
        if ($backingValue !== null) {
            $this->backingValues[$name] = $backingValue;
        }
        return $tag;
    }

    public function getCaseTag(string $name): int
    {
        assert(isset($this->cases[$name]), "enum case {$name} not found on {$this->name}");
        return $this->cases[$name];
    }
}

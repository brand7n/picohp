<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

/**
 * Manages LLVM debug metadata nodes (!DI* entries) for DWARF emission.
 * Each node gets a unique ID (!0, !1, ...) and is emitted at the end of the module.
 */
class DebugInfo
{
    private int $nextId = 0;

    /** @var array<int, string> id → metadata node text */
    private array $nodes = [];

    private ?int $compileUnitId = null;
    private ?int $fileId = null;

    /** @var array<string, int> absolute path → DIFile id */
    private array $fileCache = [];

    /** @var array<string, int> function name → DISubprogram id */
    private array $subprograms = [];

    /** @var array<string, int> "file:line" → DILocation id (cache) */
    private array $locationCache = [];

    private ?int $currentScope = null;

    public function addNode(string $text): int
    {
        $id = $this->nextId++;
        $this->nodes[$id] = $text;
        return $id;
    }

    public function initCompileUnit(string $filename, string $directory): void
    {
        $this->fileId = $this->addNode("!DIFile(filename: \"{$filename}\", directory: \"{$directory}\")");
        $this->compileUnitId = $this->addNode(
            "distinct !DICompileUnit(language: DW_LANG_C, file: !{$this->fileId}, producer: \"picoHP\", isOptimized: false, runtimeVersion: 0, emissionKind: FullDebug)"
        );
    }

    public function getFileId(): ?int
    {
        return $this->fileId;
    }

    /**
     * Get or create a DIFile node for the given absolute source path.
     */
    public function getOrCreateFileId(string $absolutePath): int
    {
        if (isset($this->fileCache[$absolutePath])) {
            return $this->fileCache[$absolutePath];
        }
        $filename = basename($absolutePath);
        $directory = dirname($absolutePath);
        $id = $this->addNode("!DIFile(filename: \"{$filename}\", directory: \"{$directory}\")");
        $this->fileCache[$absolutePath] = $id;
        return $id;
    }

    public function getCompileUnitId(): ?int
    {
        return $this->compileUnitId;
    }

    public function addSubprogram(string $funcName, int $line, ?int $fileId = null): int
    {
        $fid = $fileId ?? $this->fileId;
        \App\PicoHP\CompilerInvariant::check($fid !== null);
        $spTypeId = $this->addNode('!DISubroutineType(types: !{})');
        $id = $this->addNode(
            "distinct !DISubprogram(name: \"{$funcName}\", scope: !{$fid}, file: !{$fid}, line: {$line}, type: !{$spTypeId}, scopeLine: {$line}, unit: !{$this->compileUnitId}, spFlags: DISPFlagDefinition)"
        );
        $this->subprograms[$funcName] = $id;
        return $id;
    }

    public function getSubprogramId(string $funcName): ?int
    {
        return $this->subprograms[$funcName] ?? null;
    }

    public function setCurrentScope(?int $scopeId): void
    {
        $this->currentScope = $scopeId;
    }

    public function getCurrentScope(): ?int
    {
        return $this->currentScope;
    }

    /**
     * Get or create a DILocation for the given line. Returns metadata node ID.
     */
    public function getLocation(int $line, int $column = 0): ?int
    {
        if ($this->currentScope === null) {
            return null;
        }
        $key = "{$this->currentScope}:{$line}:{$column}";
        if (isset($this->locationCache[$key])) {
            return $this->locationCache[$key];
        }
        $id = $this->addNode("!DILocation(line: {$line}, column: {$column}, scope: !{$this->currentScope})");
        $this->locationCache[$key] = $id;
        return $id;
    }

    /**
     * @return array<string> Lines to append at end of module
     */
    public function getMetadataLines(): array
    {
        if ($this->compileUnitId === null) {
            return [];
        }
        $lines = [];
        $lines[] = '';
        $lines[] = '!llvm.dbg.cu = !{!' . $this->compileUnitId . '}';
        $lines[] = '!llvm.module.flags = !{!' . $this->addNode('!{i32 2, !"Debug Info Version", i32 3}') . ', !' . $this->addNode('!{i32 7, !"Dwarf Version", i32 4}') . '}';
        $lines[] = '';
        foreach ($this->nodes as $id => $text) {
            $lines[] = "!{$id} = {$text}";
        }
        return $lines;
    }
}

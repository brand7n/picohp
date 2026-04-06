<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\BuiltinRegistry;
use App\PicoHP\LLVM\{Module, Builder};
use App\PicoHP\Pass\IRGen\{BuildExprTrait, BuildStmtTrait, BuiltinEmitTrait, VirtualDispatchTrait};
use App\PicoHP\SymbolTable\{ClassMetadata, EnumMetadata};

class IRGenerationPass implements \App\PicoHP\PassInterface
{
    use BuildStmtTrait;
    use BuildExprTrait;
    use BuiltinEmitTrait;
    use VirtualDispatchTrait;

    public Module $module;
    protected Builder $builder;
    protected BuiltinRegistry $builtinRegistry;
    protected CodegenContext $ctx;

    /** @var list<string|null> */
    protected array $namespaceStack = [];

    /**
     * @var array<\PhpParser\Node> $stmts
     */
    protected array $stmts;

    /** @var array<string, ClassMetadata> */
    protected array $classRegistry = [];

    /** @var array<string, EnumMetadata> */
    protected array $enumRegistry = [];

    /** @var array<string, int> class name => type_id */
    protected array $typeIdMap = [];

    protected ?string $sourceFile = null;

    protected int $vdispatchCount = 0;

    /** @var array<string> stack of break target block names (switch end or innermost loop end) */
    protected array $breakTargets = [];

    /** @var array<string> stack of continue target block names (loop increment / cond) */
    protected array $continueTargets = [];

    /**
     * @param array<\PhpParser\Node> $stmts
     * @param array<string, ClassMetadata> $classRegistry
     * @param array<string, EnumMetadata> $enumRegistry
     * @param array<string, int> $typeIdMap
     */
    public function __construct(array $stmts, array $classRegistry = [], array $enumRegistry = [], array $typeIdMap = [], ?string $sourceFile = null, ?BuiltinRegistry $builtinRegistry = null)
    {
        $this->module = new Module("test_module");
        $this->builder = $this->module->getBuilder();
        $this->stmts = $stmts;
        $this->classRegistry = $classRegistry;
        $this->enumRegistry = $enumRegistry;
        $this->typeIdMap = $typeIdMap;
        $this->sourceFile = $sourceFile;
        $this->builtinRegistry = $builtinRegistry ?? BuiltinRegistry::createDefault();
        $this->ctx = new CodegenContext();
    }

    protected function pushNamespace(?string $namespace): void
    {
        $this->namespaceStack[] = $namespace;
    }

    protected function popNamespace(): void
    {
        array_pop($this->namespaceStack);
    }

    protected function currentNamespace(): ?string
    {
        if ($this->namespaceStack === []) {
            return null;
        }

        return $this->namespaceStack[array_key_last($this->namespaceStack)];
    }

    public function exec(): void
    {
        if ($this->sourceFile !== null) {
            // For single-file builds, use the file directly.
            // For directory builds, initCompileUnit with a placeholder —
            // per-function DISubprograms use the actual source file from AST attributes.
            $dir = is_dir($this->sourceFile) ? $this->sourceFile : dirname($this->sourceFile);
            $file = is_dir($this->sourceFile) ? 'picoHP' : basename($this->sourceFile);
            $this->module->getDebugInfo()->initCompileUnit($file, $dir);
        }
        $this->emitStructDefinitionsForRegistry();
        $this->emitBuiltinClasses();
        $this->buildStmts($this->stmts);
    }
}

<?php

namespace App\PicoHP\Pass;

use App\PicoHP\AstContextFormatter;
use App\PicoHP\CompilerInvariantException;
use App\PicoHP\{PassInterface, SymbolTable};
use App\PicoHP\SymbolTable\{ClassMetadata, DocTypeParser, EnumMetadata, PicoHPData};
use App\PicoHP\{BaseType, BuiltinRegistry, ClassSymbol, PicoType};

class SemanticAnalysisPass implements PassInterface
{
    protected SymbolTable $symbolTable;
    protected DocTypeParser $docTypeParser;
    protected BuiltinRegistry $builtinRegistry;
    protected ?PicoType $currentFunctionReturnType = null;
    protected ?\App\PicoHP\SymbolTable\Symbol $currentFunctionSymbol = null;
    protected ?ClassMetadata $currentClass = null;

    /** @var array<string, ClassMetadata> Keys are FQCN (e.g. {@code PhpParser\Lexer}). */
    protected array $classRegistry = [];

    /** @var list<string|null> Current namespace prefix for {@see resolveStmt} (stack for nested {@code namespace { }} blocks). */
    protected array $namespaceStack = [];

    /** @var array<string, EnumMetadata> */
    protected array $enumRegistry = [];

    /** @var array<string, array{properties: array<\PhpParser\Node\Stmt\Property>, methods: array<\PhpParser\Node\Stmt\ClassMethod>}> */
    protected array $traitRegistry = [];

    /**
     * @param array<\PhpParser\Node> $ast
     * @param \Closure(string): void|null $semanticWarning Invoked for unsupported constructs that are allowed to proceed (anonymous classes, dynamic static calls)
     */
    public function __construct(
        protected array $ast,
        private readonly ?\Closure $semanticWarning = null,
        /** When true, method/function bodies that fail semantic analysis are stubbed (abort at runtime) instead of erroring. */
        private readonly bool $allowStubbing = false,
        ?BuiltinRegistry $builtinRegistry = null,
    ) {
        $this->symbolTable = new SymbolTable();
        $this->docTypeParser = new DocTypeParser();
        $this->builtinRegistry = $builtinRegistry ?? BuiltinRegistry::createDefault();
    }

    private function emitSemanticWarning(string $label, \PhpParser\Node $node): void
    {
        if ($this->semanticWarning === null) {
            return;
        }
        $msg = '[Semantic warning] ' . $label . ': ' . AstContextFormatter::format($node);
        ($this->semanticWarning)($msg);
    }

    /**
     * Raw PHPDoc text for {@code @var} on a property. Prefer {@see \PhpParser\Node\Stmt\Property::getDocComment()}
     * (T_DOC_COMMENT); otherwise accept a preceding block comment containing {@code @var}, which PhpParser stores
     * as {@see \PhpParser\Comment} (not {@see \PhpParser\Comment\Doc}), so {@code getDocComment()} is null.
     */
    private function docTextForPropertyVarTag(\PhpParser\Node\Stmt\Property $property): ?string
    {
        $doc = $property->getDocComment();
        if ($doc !== null) {
            return $doc->getText();
        }
        foreach (array_reverse($property->getComments()) as $comment) {
            $text = $comment->getText();
            if (!str_contains($text, '@var')) {
                continue; // @codeCoverageIgnore
            }
            if (str_starts_with($text, '/*') && !str_starts_with($text, '/**')) {
                return '/**'.substr($text, 2);
            }

            return $text; // @codeCoverageIgnore
        }

        return null;
    }

    /**
     * PHPDoc / block comment with {@code @var} for a constructor-promoted parameter (same idea as
     * {@see docTextForPropertyVarTag} for {@see \PhpParser\Node\Stmt\Property}).
     *
     * Plain block comments containing {@code @var} (non-T_DOC_COMMENT) are normalized to docblock shape
     * so {@see DocTypeParser::parseType} sees a single PHPDoc string (PhpParser stores those blocks as
     * {@see \PhpParser\Comment}, so {@code getDocComment()} is null for them).
     */
    private function docTextForPromotedParam(\PhpParser\Node\Param $param): ?string
    {
        $doc = $param->getDocComment();
        if ($doc !== null) {
            return $doc->getText();
        }
        foreach (array_reverse($param->getComments()) as $comment) {
            $text = $comment->getText();
            if (!str_contains($text, '@var')) {
                continue; // @codeCoverageIgnore
            }
            if (str_starts_with($text, '/*') && !str_starts_with($text, '/**')) {
                return '/**'.substr($text, 2);
            }

            return $text; // @codeCoverageIgnore
        }

        return null;
    }

    /**
     * Register instance properties declared via PHP 8+ constructor promotion.
     */
    private function registerPromotedConstructorParams(ClassMetadata $classMeta, \PhpParser\Node\Stmt\ClassMethod $method): void
    {
        if ($method->name->toString() !== '__construct') {
            return;
        }
        foreach ($method->params as $param) {
            if (!$param->isPromoted()) {
                continue;
            }
            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
            $propName = $param->var->name;
            if ($param->type === null) {
                $docText = $this->docTextForPromotedParam($param);
                \App\PicoHP\CompilerInvariant::check(
                    $docText !== null,
                    'untyped promoted constructor parameter requires PHPDoc type annotation ('.AstContextFormatter::format($param).')',
                );
                $propType = $this->docTypeParser->parseType($docText);
            } else {
                \App\PicoHP\CompilerInvariant::check($param->type instanceof \PhpParser\Node\Identifier || $param->type instanceof \PhpParser\Node\NullableType || $param->type instanceof \PhpParser\Node\Name || $param->type instanceof \PhpParser\Node\UnionType);
                $docText = $this->docTextForPromotedParam($param);
                if ($docText !== null) {
                    $propType = $this->docTypeParser->parseType($docText);
                } else {
                    $propType = $this->typeFromNode($param->type);
                }
            }
            $effectiveType = $propType;
            if ($propType->isArray() && $propType->getElementType()->toBase() === BaseType::PTR
                && isset($classMeta->properties[$propName]) && $classMeta->properties[$propName]->isArray()
                && $classMeta->properties[$propName]->getElementType()->toBase() !== BaseType::PTR) {
                $effectiveType = $classMeta->properties[$propName]; // @codeCoverageIgnore
            }
            $classMeta->addProperty($propName, $effectiveType);
            if ($param->default !== null) {
                $classMeta->propertyDefaults[$propName] = $param->default;
            }
        }
    }

    /**
     * @return array<string, ClassMetadata>
     */
    public function getClassRegistry(): array
    {
        return $this->classRegistry;
    }

    /**
     * @return array<string, EnumMetadata>
     */
    public function getEnumRegistry(): array
    {
        return $this->enumRegistry;
    }

    /** @var int */
    protected int $nextTypeId = 1;

    /** @var array<string, int> class name => type_id for exception type matching */
    protected array $typeIdMap = [];

    /**
     * @return array<string, int>
     */
    public function getTypeIdMap(): array
    {
        return $this->typeIdMap;
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
        $this->registerBuiltinClasses();
        $finder = new \PhpParser\NodeFinder();
        foreach ($finder->findInstanceOf($this->ast, \PhpParser\Node\Stmt\Class_::class) as $classNode) {
            if ($classNode->name === null) {
                $this->emitSemanticWarning('Anonymous class', $classNode);
            }
        }
        $this->registerClassStubs($this->ast);
        $this->registerClasses($this->ast);
        $this->mergeMissingInstancePropertiesFromReflectionAll();
        $this->registerFunctions($this->ast);
        $this->resolveStmts($this->ast);
        $this->propagateCanThrow($this->ast);
    }

    /**
     * Propagate canThrow transitively: if a function calls a canThrow function
     * (and doesn't catch it), the caller also canThrow. Runs to fixpoint.
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function propagateCanThrow(array $stmts): void
    {
        $finder = new \PhpParser\NodeFinder();

        // Collect all function/method symbols
        /** @var list<\App\PicoHP\SymbolTable\Symbol> $funcSymbols */
        $funcSymbols = [];
        /** @var array<string, list<string>> $callGraph function name → list of callee names */
        $callGraph = [];

        foreach ($finder->findInstanceOf($stmts, \PhpParser\Node\Stmt\Function_::class) as $funcNode) {
            $pData = PicoHPData::getPData($funcNode);
            if ($pData->symbol === null) {
                continue;
            }
            $sym = $pData->symbol;
            $funcSymbols[$sym->name] = $sym;
            $callGraph[$sym->name] = [];

            // Find all FuncCall nodes in this function's body
            foreach ($finder->findInstanceOf($funcNode->stmts, \PhpParser\Node\Expr\FuncCall::class) as $callNode) {
                if ($callNode->name instanceof \PhpParser\Node\Name) {
                    $callGraph[$sym->name][] = $callNode->name->toLowerString();
                }
            }
        }

        // Fixpoint: propagate canThrow
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($callGraph as $callerName => $calleeNames) {
                if ($funcSymbols[$callerName]->canThrow) {
                    continue;
                }
                foreach ($calleeNames as $calleeName) {
                    if (isset($funcSymbols[$calleeName]) && $funcSymbols[$calleeName]->canThrow) {
                        $funcSymbols[$callerName]->canThrow = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }
    }

    protected function registerBuiltinClasses(): void
    {
        // Register classes from builtins/ header files (Exception hierarchy, etc.)
        // Process in dependency order: parents before children
        $classDefs = $this->builtinRegistry->allClasses();
        $registered = [];
        while (count($registered) < count($classDefs)) {
            $progress = false;
            foreach ($classDefs as $name => $classDef) {
                if (isset($registered[$name])) {
                    continue;
                }
                if ($classDef->parentName !== null && isset($classDefs[$classDef->parentName]) && !isset($registered[$classDef->parentName])) {
                    continue;
                }
                $this->registerBuiltinClassFromDef($classDef);
                $registered[$name] = true;
                $progress = true;
            }
            if (!$progress) {
                break;
            }
        }

        // Optional Composer types not guaranteed to be autoloadable
        $symfonyEventFqcn = 'Symfony\Contracts\EventDispatcher\Event';
        if (!isset($this->classRegistry[$symfonyEventFqcn])) {
            $this->classRegistry[$symfonyEventFqcn] = new ClassMetadata($symfonyEventFqcn);
            $this->typeIdMap[$symfonyEventFqcn] = $this->nextTypeId++;
        }
    }

    protected function registerBuiltinClassFromDef(\App\PicoHP\BuiltinClassDef $classDef): void
    {
        $meta = new ClassMetadata($classDef->name);

        if ($classDef->isInterface) {
            foreach ($classDef->methods as $methodName => $methodDef) {
                $symbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $methodDef->returnType, func: true);
                $meta->methods[$methodName] = $symbol;
                $meta->methodOwner[$methodName] = $classDef->name;
            }
            $this->classRegistry[$classDef->name] = $meta;
            $this->typeIdMap[$classDef->name] = $this->nextTypeId++;

            return;
        }

        // Inherit from parent
        if ($classDef->parentName !== null && isset($this->classRegistry[$classDef->parentName])) {
            $meta->inheritFrom($this->classRegistry[$classDef->parentName]);
        }

        // Record implemented interfaces
        foreach ($classDef->interfaces as $ifaceName) {
            $meta->interfaces[] = $ifaceName;
        }

        // Add properties
        foreach ($classDef->properties as $propName => $propType) {
            $meta->addProperty($propName, $propType);
        }

        // Add methods
        foreach ($classDef->methods as $methodName => $methodDef) {
            $symbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $methodDef->returnType, func: true);
            $symbol->params = array_map(
                static fn (array $p): PicoType => $p['type'],
                $methodDef->params,
            );
            $symbol->paramNames = [];
            foreach ($methodDef->params as $i => $p) {
                $symbol->paramNames[$i] = $p['name'];
            }
            $meta->methods[$methodName] = $symbol;
            $meta->methodOwner[$methodName] = $classDef->name;
        }

        $this->classRegistry[$classDef->name] = $meta;
        $this->typeIdMap[$classDef->name] = $this->nextTypeId++;
    }

    /**
     * Register a class or interface that exists in the host PHP (Composer autoload) but may be absent from the
     * merged AST — SPL exceptions, vendor bases, etc. Walks the real parent chain via reflection.
     */
    protected function ensureExternalClassReference(string $fqcn): void
    {
        if (isset($this->classRegistry[$fqcn])) {
            $meta = $this->classRegistry[$fqcn];
            // registerClassStubs() may insert an empty placeholder for merged vendor AST classes.
            // Only php-parser types are safe to replace: user classes can be empty until traits apply.
            if ($meta->methods === [] && str_starts_with($fqcn, 'PhpParser\\')) {
                if (class_exists($fqcn, true) || interface_exists($fqcn, true)) {
                    if ($fqcn === 'PhpParser\ParserAbstract') {
                        if (isset($meta->properties['lexer'])) {
                        }
                    }
                    unset($this->classRegistry[$fqcn], $this->typeIdMap[$fqcn]);
                    $this->registerClassHierarchyFromReflection($fqcn);
                    if ($fqcn === 'PhpParser\ParserAbstract' && isset($this->classRegistry[$fqcn])) {
                        $newMeta = $this->classRegistry[$fqcn];
                        if (isset($newMeta->properties['lexer'])) {
                        }
                    }
                }
            }

            if (isset($this->classRegistry[$fqcn])) {
                $this->mergeMissingInstancePropertiesForFqcn($fqcn);
            }

            return;
        }
        $this->registerClassHierarchyFromReflection($fqcn);
    }

    /**
     * @return bool True if {@code $fqcn} is now in the registry (or was already)
     */
    protected function registerClassHierarchyFromReflection(string $fqcn): bool
    {
        if (isset($this->classRegistry[$fqcn])) {
            return true;
        }
        if (interface_exists($fqcn, true)) {
            $ref = new \ReflectionClass($fqcn);
            if (!$ref->isInterface()) {
                return false; // @codeCoverageIgnore
            }
            $meta = new ClassMetadata($fqcn);
            $this->classRegistry[$fqcn] = $meta;
            $this->typeIdMap[$fqcn] = $this->nextTypeId++;
            $this->registerInstanceMethodsFromReflection($meta, $fqcn);

            return true;
        }
        if (!class_exists($fqcn, true)) {
            return false;
        }
        $ref = new \ReflectionClass($fqcn);
        if ($ref->isTrait()) {
            return false;
        }
        $parent = $ref->getParentClass();
        if ($parent === false) {
            $meta = new ClassMetadata($fqcn);
            $this->classRegistry[$fqcn] = $meta;
            $this->typeIdMap[$fqcn] = $this->nextTypeId++;
            $this->registerInstanceMethodsFromReflection($meta, $fqcn);
            $this->registerInstancePropertiesFromReflection($meta, $fqcn);

            return true;
        }
        $parentName = $parent->getName();
        if (!isset($this->classRegistry[$parentName])) {
            if (!$this->registerClassHierarchyFromReflection($parentName)) {
                return false; // @codeCoverageIgnore
            }
        }
        if (!isset($this->classRegistry[$parentName])) {
            return false; // @codeCoverageIgnore
        }
        $meta = new ClassMetadata($fqcn);
        $meta->inheritFrom($this->classRegistry[$parentName]);
        $this->classRegistry[$fqcn] = $meta;
        $this->typeIdMap[$fqcn] = $this->nextTypeId++;

        return true;
    }

    /**
     * Populate {@see ClassMetadata::methods} from PHP reflection for external interfaces and root classes
     * (e.g. {@code PhpParser\Node}, {@code PhpParser\NodeAbstract}) so method calls used in the compiler
     * against vendor types resolve during semantic analysis.
     */
    protected function registerInstanceMethodsFromReflection(ClassMetadata $meta, string $fqcn): void
    {
        if (!class_exists($fqcn, true) && !interface_exists($fqcn, true)) {
            return; // @codeCoverageIgnore
        }
        /** @var class-string $fqcn */
        $ref = new \ReflectionClass($fqcn);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->isStatic() || $rm->isConstructor()) {
                continue; // @codeCoverageIgnore
            }
            if ($rm->getDeclaringClass()->getName() !== $fqcn) {
                continue; // @codeCoverageIgnore
            }
            $methodName = $rm->getName();
            $returnType = $this->picoTypeFromReflectionReturnType($rm);
            $params = [];
            foreach ($rm->getParameters() as $rp) {
                $params[] = $this->picoTypeFromReflectionParameter($rp);
            }
            $sym = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, $params, func: true);
            $pi = 0;
            foreach ($rm->getParameters() as $rp) {
                $sym->paramNames[$pi] = $rp->getName();
                if ($rp->isDefaultValueAvailable()) {
                    $sym->defaults[$pi] = null;
                }
                $pi++;
            }
            $meta->methods[$methodName] = $sym;
            $meta->methodOwner[$methodName] = $fqcn;
        }
    }

    /**
     * Directory builds merge many files; some class bodies may not be represented on the transformed AST
     * while the class is still in {@see $classRegistry} (stubs), or registration can be partial. Add any
     * instance property that exists in PHP reflection but is missing from {@see ClassMetadata::properties}.
     */
    protected function mergeMissingInstancePropertiesFromReflectionAll(): void
    {
        foreach ($this->classRegistry as $fqcn => $_meta) {
            $this->mergeMissingInstancePropertiesForFqcn($fqcn);
        }
    }

    /**
     * Same as {@see mergeMissingInstancePropertiesFromReflectionAll} for a single FQCN (e.g. before property fetch).
     */
    protected function mergeMissingInstancePropertiesForFqcn(string $fqcn): void
    {
        if (!isset($this->classRegistry[$fqcn])) {
            return;
        }
        $meta = $this->classRegistry[$fqcn];
        if (!class_exists($fqcn, true) || trait_exists($fqcn, false)) {
            return;
        }
        $ref = new \ReflectionClass($fqcn);
        if ($ref->isInterface()) {
            return; // @codeCoverageIgnore
        }
        foreach ($ref->getProperties() as $rp) {
            if ($rp->isStatic()) {
                continue; // @codeCoverageIgnore
            }
            if ($rp->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }
            $propName = $rp->getName();
            if (isset($meta->properties[$propName])) {
                continue;
            }
            $meta->addProperty($propName, $this->picoTypeFromReflectionProperty($rp));
        }
    }

    /**
     * Instance properties for classes registered only via reflection (directory builds where the class
     * body is not on the transformed AST). Without this, property fetch on e.g. {@see \App\PicoHP\HandLexer\Token}
     * fails after {@see registerInstanceMethodsFromReflection}.
     */
    protected function registerInstancePropertiesFromReflection(ClassMetadata $meta, string $fqcn): void
    {
        if (!class_exists($fqcn, true)) {
            return; // @codeCoverageIgnore
        }
        /** @var class-string $fqcn */
        $ref = new \ReflectionClass($fqcn);
        foreach ($ref->getProperties() as $rp) {
            if ($rp->isStatic()) { // @codeCoverageIgnore
                continue; // @codeCoverageIgnore
            }
            if ($rp->getDeclaringClass()->getName() !== $fqcn) { // @codeCoverageIgnore
                continue; // @codeCoverageIgnore
            }
            $meta->addProperty($rp->getName(), $this->picoTypeFromReflectionProperty($rp)); // @codeCoverageIgnore
        }
    }

    protected function picoTypeFromReflectionProperty(\ReflectionProperty $rp): PicoType
    {
        $rt = $rp->getType();
        if ($rt === null) {
            return PicoType::fromString('mixed');
        }
        if ($rt instanceof \ReflectionUnionType || $rt instanceof \ReflectionIntersectionType) {
            return PicoType::fromString('mixed');
        }
        if (!($rt instanceof \ReflectionNamedType)) {
            return PicoType::fromString('mixed'); // @codeCoverageIgnore
        }
        $name = $rt->getName();
        $builtin = match ($name) {
            'int', 'float', 'bool', 'string', 'void', 'mixed', 'array', 'callable', 'iterable', 'object' => $name,
            'false', 'true' => 'bool', // @codeCoverageIgnore
            default => null,
        };
        if ($builtin !== null) {
            return $rt->allowsNull() ? PicoType::fromString('?'.$builtin) : PicoType::fromString($builtin);
        }
        if (enum_exists($name)) {
            return PicoType::enum($name);
        }
        if (class_exists($name) || interface_exists($name)) {
            if ($name === 'PhpParser\Lexer') {
            }
            return $rt->allowsNull() ? PicoType::fromString('?'.$name) : PicoType::object($name);
        }

        return PicoType::fromString('mixed'); // @codeCoverageIgnore
    }

    protected function picoTypeFromReflectionReturnType(\ReflectionMethod $rm): PicoType
    {
        $rt = $rm->getReturnType();
        if ($rt === null) {
            return PicoType::fromString('mixed');
        }
        if ($rt instanceof \ReflectionUnionType || $rt instanceof \ReflectionIntersectionType) {
            return PicoType::fromString('mixed');
        }
        if ($rt instanceof \ReflectionNamedType) {
            $inner = $this->picoTypeNameFromReflectionNamedType($rt);
            if ($inner === 'void') {
                return PicoType::fromString('void');
            }
            if ($rt->allowsNull()) {
                return PicoType::fromString('?'.$inner);
            }

            return PicoType::fromString($inner);
        }

        return PicoType::fromString('mixed'); // @codeCoverageIgnore
    }

    protected function picoTypeFromReflectionParameter(\ReflectionParameter $rp): PicoType
    {
        $rt = $rp->getType();
        if ($rt === null) {
            return PicoType::fromString('mixed');
        }
        if ($rt instanceof \ReflectionUnionType || $rt instanceof \ReflectionIntersectionType) {
            return PicoType::fromString('mixed');
        }
        if ($rt instanceof \ReflectionNamedType) {
            if ($rt->allowsNull()) {
                return PicoType::fromString('?'.$this->picoTypeNameFromReflectionNamedType($rt)); // @codeCoverageIgnore
            }

            return PicoType::fromString($this->picoTypeNameFromReflectionNamedType($rt));
        }

        return PicoType::fromString('mixed'); // @codeCoverageIgnore
    }

    /**
     * Map a single {@see ReflectionNamedType} to a picohp type string (no leading {@code ?}).
     */
    protected function picoTypeNameFromReflectionNamedType(\ReflectionNamedType $rt): string
    {
        $n = $rt->getName();

        return match ($n) {
            'int', 'float', 'bool', 'string', 'void', 'mixed', 'array', 'callable', 'iterable', 'object' => $n,
            'false', 'true' => 'bool', // @codeCoverageIgnore
            default => 'mixed',
        };
    }

    /**
     * Register every named class with an empty {@see ClassMetadata} so parent/child order in the merged AST
     * does not matter (e.g. a class before its parent in the merged AST).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerClassStubs(array $stmts, ?string $namespace = null): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $childNs = $stmt->name !== null ? $stmt->name->toString() : '';
                $merged = $childNs === '' ? null : $childNs;
                $this->registerClassStubs($stmt->stmts, $merged);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
                $this->registerClassStubs($stmt->stmts, $namespace);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Class_ && $stmt->name !== null) {
                $fqcn = ClassSymbol::fqcn($namespace, $stmt->name->toString());
                if (!isset($this->classRegistry[$fqcn])) {
                    $this->classRegistry[$fqcn] = new ClassMetadata($fqcn);
                    $this->typeIdMap[$fqcn] = $this->nextTypeId++;
                }
            }
        }
    }

    /**
     * Pre-pass: register class metadata (properties and method signatures).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerClasses(array $stmts, ?string $namespace = null): void
    {
        // Pre-pass: register all traits before processing classes
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $childNs = $stmt->name !== null ? $stmt->name->toString() : '';
                $merged = $childNs === '' ? null : $childNs;
                $this->registerTraits($stmt->stmts, $merged);
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
                $this->registerTrait($stmt, $namespace);
            }
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $childNs = $stmt->name !== null ? $stmt->name->toString() : '';
                $merged = $childNs === '' ? null : $childNs;
                $this->registerClasses($stmt->stmts, $merged);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
                $this->registerClasses($stmt->stmts, $namespace);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
                if ($stmt->name === null) {
                    continue; // @codeCoverageIgnore
                }
                $fqcn = ClassSymbol::fqcn($namespace, $stmt->name->toString());
                if (!isset($this->classRegistry[$fqcn])) {
                    $this->classRegistry[$fqcn] = new ClassMetadata($fqcn); // @codeCoverageIgnore
                }
                if (!isset($this->typeIdMap[$fqcn])) {
                    $this->typeIdMap[$fqcn] = $this->nextTypeId++; // @codeCoverageIgnore
                }
                $classMeta = $this->classRegistry[$fqcn];
                $classMeta->isAbstract = $stmt->isAbstract();
                // Inherit from parent class
                if ($stmt->extends !== null) {
                    $parentName = ClassSymbol::fqcnFromResolvedName($stmt->extends, $namespace);
                    $this->ensureExternalClassReference($parentName);
                    \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$parentName]), "parent class {$parentName} not found");
                    $classMeta->inheritFrom($this->classRegistry[$parentName]);
                }
                // Track implemented interfaces
                foreach ($stmt->implements as $iface) {
                    $classMeta->interfaces[] = ClassSymbol::fqcnFromResolvedName($iface, $namespace);
                }
                // Inline trait members into class AST
                $inlinedStmts = [];
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\TraitUse) {
                        foreach ($classStmt->traits as $traitName) {
                            $name = ClassSymbol::fqcnFromResolvedName($traitName, $namespace);
                            \App\PicoHP\CompilerInvariant::check(isset($this->traitRegistry[$name]), "trait {$name} not found");
                            $trait = $this->traitRegistry[$name];
                            foreach ($trait['properties'] as $prop) {
                                $inlinedStmts[] = clone $prop;
                            }
                            foreach ($trait['methods'] as $method) {
                                $inlinedStmts[] = clone $method;
                            }
                        }
                    } else {
                        $inlinedStmts[] = $classStmt;
                    }
                }
                $stmt->stmts = $inlinedStmts;
                // Push namespace so typeFromNode resolves class names correctly
                $this->namespaceStack[] = $namespace;
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\Property) {
                        if ($classStmt->type === null) {
                            // No native type hint — require PHPDoc (or block comment with @var; see docTextForPropertyVarTag)
                            $docText = $this->docTextForPropertyVarTag($classStmt);
                            \App\PicoHP\CompilerInvariant::check(
                                $docText !== null,
                                'untyped property requires PHPDoc type annotation ('.AstContextFormatter::format($classStmt).')',
                            );
                            $propType = $this->docTypeParser->parseType($docText);
                        } else {
                            \App\PicoHP\CompilerInvariant::check($classStmt->type instanceof \PhpParser\Node\Identifier || $classStmt->type instanceof \PhpParser\Node\NullableType || $classStmt->type instanceof \PhpParser\Node\Name || $classStmt->type instanceof \PhpParser\Node\UnionType);
                            if ($classStmt->type instanceof \PhpParser\Node\Name && $classStmt->type->toString() === 'Lexer') {
                                $rn = $classStmt->type->getAttribute('resolvedName');
                            }
                            $docText = $this->docTextForPropertyVarTag($classStmt);
                            if ($docText !== null) {
                                $propType = $this->docTypeParser->parseType($docText);
                            } else {
                                $propType = $this->typeFromNode($classStmt->type);


                            }
                        }
                        if ($classStmt->isStatic()) {
                            foreach ($classStmt->props as $prop) {
                                $classMeta->staticProperties[$prop->name->toString()] = $propType;
                                $classMeta->staticDefaults[$prop->name->toString()] = $prop->default;
                            }
                        } else {
                            foreach ($classStmt->props as $prop) {
                                $propName = $prop->name->toString();
                                // If parent has a richer type (e.g. array<int,int> vs bare array),
                                // prefer parent's type so element type info isn't lost
                                $effectiveType = $propType;
                                if ($propType->isArray() && $propType->getElementType()->toBase() === BaseType::PTR
                                    && isset($classMeta->properties[$propName]) && $classMeta->properties[$propName]->isArray()
                                    && $classMeta->properties[$propName]->getElementType()->toBase() !== BaseType::PTR) {
                                    $effectiveType = $classMeta->properties[$propName];
                                }
                                $classMeta->addProperty($propName, $effectiveType);
                                if ($prop->default !== null) {
                                    $classMeta->propertyDefaults[$propName] = $prop->default;
                                }
                            }
                        }
                    } elseif ($classStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $classStmt->name->toString();
                        $this->registerPromotedConstructorParams($classMeta, $classStmt);
                        \App\PicoHP\CompilerInvariant::check($classStmt->returnType === null || $classStmt->returnType instanceof \PhpParser\Node\Identifier || $classStmt->returnType instanceof \PhpParser\Node\NullableType || $classStmt->returnType instanceof \PhpParser\Node\Name || $classStmt->returnType instanceof \PhpParser\Node\UnionType || $classStmt->returnType instanceof \PhpParser\Node\IntersectionType);
                        $returnType = $this->resolveMethodReturnTypeFromClassMethodNode($classStmt);
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $pi = 0;
                        foreach ($classStmt->params as $param) {
                            $methodSymbol->defaults[$pi] = $param->default;
                            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                            $methodSymbol->paramNames[$pi] = $param->var->name;
                            $pi++;
                        }
                        $methodSymbol->isAbstract = $classStmt->isAbstract();
                        $classMeta->methods[$methodName] = $methodSymbol;
                        $classMeta->methodOwner[$methodName] = $fqcn;
                    } elseif ($classStmt instanceof \PhpParser\Node\Stmt\ClassConst) {
                        foreach ($classStmt->consts as $const) {
                            if ($const->value instanceof \PhpParser\Node\Scalar\Int_) {
                                $classMeta->constants[$const->name->toString()] = $const->value->value;
                            }
                        }
                    }
                }
                array_pop($this->namespaceStack);
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
                \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
                $ifaceName = ClassSymbol::fqcn($namespace, $stmt->name->toString());
                $ifaceMeta = new ClassMetadata($ifaceName);
                $this->classRegistry[$ifaceName] = $ifaceMeta;
                foreach ($stmt->stmts as $ifaceStmt) {
                    if ($ifaceStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $ifaceStmt->name->toString();
                        \App\PicoHP\CompilerInvariant::check($ifaceStmt->returnType === null || $ifaceStmt->returnType instanceof \PhpParser\Node\Identifier || $ifaceStmt->returnType instanceof \PhpParser\Node\NullableType || $ifaceStmt->returnType instanceof \PhpParser\Node\Name || $ifaceStmt->returnType instanceof \PhpParser\Node\UnionType || $ifaceStmt->returnType instanceof \PhpParser\Node\IntersectionType);
                        $returnType = $this->resolveMethodReturnTypeFromClassMethodNode($ifaceStmt);
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $pi = 0;
                        foreach ($ifaceStmt->params as $param) {
                            $methodSymbol->defaults[$pi] = $param->default;
                            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                            $methodSymbol->paramNames[$pi] = $param->var->name;
                            $pi++;
                        }
                        $ifaceMeta->methods[$methodName] = $methodSymbol;
                        $ifaceMeta->methodOwner[$methodName] = $ifaceName;
                    }
                }
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
                \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
                $enumName = ClassSymbol::fqcn($namespace, $stmt->name->toString());
                $scalarTypeName = null;
                if ($stmt->scalarType !== null) {
                    $scalarTypeName = $stmt->scalarType->name;
                }
                $enumMeta = new EnumMetadata($enumName, $scalarTypeName);
                $this->enumRegistry[$enumName] = $enumMeta;
                // Also register enum in classRegistry for method call resolution
                $enumClassMeta = new ClassMetadata($enumName);
                $this->classRegistry[$enumName] = $enumClassMeta;
                $this->typeIdMap[$enumName] = $this->nextTypeId++;
                foreach ($stmt->stmts as $enumStmt) {
                    if ($enumStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $enumStmt->name->toString();
                        \App\PicoHP\CompilerInvariant::check($enumStmt->returnType === null || $enumStmt->returnType instanceof \PhpParser\Node\Identifier || $enumStmt->returnType instanceof \PhpParser\Node\NullableType || $enumStmt->returnType instanceof \PhpParser\Node\Name || $enumStmt->returnType instanceof \PhpParser\Node\UnionType || $enumStmt->returnType instanceof \PhpParser\Node\IntersectionType);
                        $returnType = $enumStmt->returnType !== null
                            ? $this->typeFromNode($enumStmt->returnType)
                            : PicoType::fromString('void');
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $enumClassMeta->methods[$methodName] = $methodSymbol;
                        $enumClassMeta->methodOwner[$methodName] = $enumName;
                    }
                    if ($enumStmt instanceof \PhpParser\Node\Stmt\ClassConst) {
                        foreach ($enumStmt->consts as $const) {
                            if ($const->value instanceof \PhpParser\Node\Scalar\Int_) {
                                $enumClassMeta->constants[$const->name->toString()] = $const->value->value;
                            }
                        }
                    }
                    if ($enumStmt instanceof \PhpParser\Node\Stmt\EnumCase) {
                        $caseName = $enumStmt->name->toString();
                        $backingValue = null;
                        if ($enumStmt->expr instanceof \PhpParser\Node\Scalar\String_) {
                            $backingValue = $enumStmt->expr->value;
                        } elseif ($enumStmt->expr instanceof \PhpParser\Node\Scalar\Int_) {
                            $backingValue = $enumStmt->expr->value;
                        }
                        $enumMeta->addCase($caseName, $backingValue);
                    }
                }
                // Auto-register tryFrom/from for backed enums
                if ($scalarTypeName !== null) {
                    $paramType = $scalarTypeName === 'string' ? PicoType::fromString('string') : PicoType::fromString('int');
                    $tryFromSym = new \App\PicoHP\SymbolTable\Symbol('tryFrom', PicoType::enum($enumName), [$paramType], func: true);
                    $tryFromSym->paramNames = [0 => 'value'];
                    $enumClassMeta->methods['tryFrom'] = $tryFromSym;
                    $enumClassMeta->methodOwner['tryFrom'] = $enumName;
                    $fromSym = new \App\PicoHP\SymbolTable\Symbol('from', PicoType::enum($enumName), [$paramType], func: true);
                    $fromSym->paramNames = [0 => 'value'];
                    $enumClassMeta->methods['from'] = $fromSym;
                    $enumClassMeta->methodOwner['from'] = $enumName;
                    // Also register mangled names as global functions (ClassToFunctionVisitor
                    // transforms Color::tryFrom() → Color_tryFrom())
                    $mangledName = ClassSymbol::mangle($enumName);
                    $globalTryFrom = new \App\PicoHP\SymbolTable\Symbol("{$mangledName}_tryFrom", PicoType::enum($enumName), [$paramType], func: true);
                    $globalTryFrom->paramNames = [0 => 'value'];
                    $this->symbolTable->addSymbol("{$mangledName}_tryFrom", PicoType::enum($enumName), func: true);
                    $this->symbolTable->addSymbol("{$mangledName}_from", PicoType::enum($enumName), func: true);
                }
            }
        }
    }

    /**
     * Pre-pass: register all traits from a list of statements.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    protected function registerTraits(array $stmts, ?string $namespace): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
                $this->registerTrait($stmt, $namespace);
            }
        }
    }

    protected function registerTrait(\PhpParser\Node\Stmt\Trait_ $stmt, ?string $namespace): void
    {
        \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
        $traitName = ClassSymbol::fqcn($namespace, $stmt->name->toString());
        $properties = [];
        $methods = [];
        foreach ($stmt->stmts as $traitStmt) {
            if ($traitStmt instanceof \PhpParser\Node\Stmt\Property) {
                $properties[] = $traitStmt;
            } elseif ($traitStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                $methods[] = $traitStmt;
            }
        }
        $this->traitRegistry[$traitName] = ['properties' => $properties, 'methods' => $methods];
    }

    /**
     * Native return type if present; else a single supported {@code @return} from PHPDoc; else {@code mixed}
     * (legacy / generated stubs often omit both).
     */
    private function resolveTopLevelFunctionReturnPicoType(\PhpParser\Node\Stmt\Function_ $stmt): PicoType
    {
        if ($stmt->returnType !== null) {
            $rt = $stmt->returnType;
            if ($rt instanceof \PhpParser\Node\Identifier
                || $rt instanceof \PhpParser\Node\NullableType
                || $rt instanceof \PhpParser\Node\Name
                || $rt instanceof \PhpParser\Node\UnionType
                || $rt instanceof \PhpParser\Node\IntersectionType) {
                return $this->typeFromNode($rt);
            }
            throw new CompilerInvariantException( // @codeCoverageIgnore
                'Unsupported native return type AST `'.get_debug_type($rt).'` for top-level function `'.$stmt->name->name.'`. '.AstContextFormatter::format($stmt), // @codeCoverageIgnore
            ); // @codeCoverageIgnore
        }
        $doc = $stmt->getDocComment(); // @codeCoverageIgnore
        if ($doc !== null) { // @codeCoverageIgnore
            $fromDoc = $this->docTypeParser->parseReturnTypeFromPhpDoc($doc->getText()); // @codeCoverageIgnore
            if ($fromDoc !== null) { // @codeCoverageIgnore
                return $fromDoc; // @codeCoverageIgnore
            }
        }

        return PicoType::fromString('mixed'); // @codeCoverageIgnore
    }

    /**
     * Pre-pass: register all top-level function declarations so they can be
     * referenced before their definition (forward references).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerFunctions(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
                $fnReturnPico = $this->resolveTopLevelFunctionReturnPicoType($stmt);
                $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
                if ($existing === null) {
                    $sym = $this->symbolTable->addSymbol(
                        $stmt->name->name,
                        $fnReturnPico,
                        func: true
                    );
                    $pi = 0;
                    foreach ($stmt->params as $param) {
                        $sym->defaults[$pi] = $param->default;
                        \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                        $sym->paramNames[$pi] = $param->var->name;
                        $pi++;
                    }
                }
            }
        }
    }

    protected function isSubclassOf(string $className, string $parentName): bool
    {
        if ($className === $parentName) {
            return true;
        }
        $meta = $this->classRegistry[$className] ?? null;
        if ($meta === null || $meta->parentName === null) {
            return false; // @codeCoverageIgnore
        }
        return $this->isSubclassOf($meta->parentName, $parentName);
    }

    /**
     * Check if rtype can be assigned to a variable/return of ltype.
     * Allows: interface to implementor, parent to child, nullable variants.
     */
    protected function isAssignmentCompatible(PicoType $ltype, PicoType $rtype): bool
    {
        // Both must be ptr-based (objects, nullable objects, arrays, strings, mixed)
        $lBase = $ltype->toBase();
        $rBase = $rtype->toBase();
        $lIsPtr = $lBase === BaseType::PTR || $lBase === BaseType::STRING;
        $rIsPtr = $rBase === BaseType::PTR || $rBase === BaseType::STRING;
        if (!$lIsPtr || !$rIsPtr) {
            return false;
        }
        // If either is an object type, check class hierarchy and interfaces
        if ($ltype->isObject() && $rtype->isObject()) {
            $lclass = $ltype->getClassName();
            $rclass = $rtype->getClassName();
            // Same class, or rtype is subclass of ltype
            if ($this->isSubclassOf($rclass, $lclass)) {
                return true;
            }
            // ltype is an interface that rtype implements
            $rmeta = $this->classRegistry[$rclass] ?? null; // @codeCoverageIgnore
            if ($rmeta !== null && in_array($lclass, $rmeta->interfaces, true)) { // @codeCoverageIgnore
                return true; // @codeCoverageIgnore
            }
            // rtype is an interface that ltype implements
            $lmeta = $this->classRegistry[$lclass] ?? null; // @codeCoverageIgnore
            if ($lmeta !== null && in_array($rclass, $lmeta->interfaces, true)) { // @codeCoverageIgnore
                return true; // @codeCoverageIgnore
            }
            // Both are in classRegistry (could be interface assigned from interface)
            if (isset($this->classRegistry[$lclass]) && isset($this->classRegistry[$rclass])) { // @codeCoverageIgnore
                return true; // @codeCoverageIgnore
            }
        }
        // Both are ptr-based (string, array, mixed, nullable) — compatible in LLVM
        return true;
    }

    private function isDescendantOf(ClassMetadata $meta, string $ancestor): bool
    {
        if (in_array($ancestor, $meta->interfaces, true)) {
            return true;
        }
        $current = $meta->parentName;
        while ($current !== null) {
            if ($current === $ancestor) { // @codeCoverageIgnore
                return true; // @codeCoverageIgnore
            }
            $current = isset($this->classRegistry[$current]) ? $this->classRegistry[$current]->parentName : null; // @codeCoverageIgnore
        }
        return false;
    }

    /**
     * Native return type from the signature, optionally overridden by a precise `@return` PHPDoc
     * (e.g. `function foo(): array` + `@return array<int, Token>`).
     */
    private function resolveMethodReturnTypeFromClassMethodNode(\PhpParser\Node\Stmt\ClassMethod $stmt): PicoType
    {
        $native = PicoType::fromString('void');
        if ($stmt->returnType !== null) {
            \App\PicoHP\CompilerInvariant::check(
                $stmt->returnType instanceof \PhpParser\Node\Identifier
                || $stmt->returnType instanceof \PhpParser\Node\NullableType
                || $stmt->returnType instanceof \PhpParser\Node\Name
                || $stmt->returnType instanceof \PhpParser\Node\UnionType
                || $stmt->returnType instanceof \PhpParser\Node\IntersectionType
            );
            $native = $this->typeFromNode($stmt->returnType);
        }
        $doc = $stmt->getDocComment();
        if ($doc === null) {
            return $native;
        }
        $fromDoc = $this->docTypeParser->parseReturnTypeFromPhpDoc($doc->getText());
        if ($fromDoc === null) {
            return $native;
        }
        // Only refine the native signature when PHP gives a bare `array` (unknown element type).
        // If we always preferred `@return`, stubs like `function f(): mixed { return null; }` with a
        // documenting `@return array<...>` would break return checking (see php8_transformed stubs).
        if ($this->shouldRefineArrayReturnTypeWithPhpDoc($native)) {
            return $fromDoc;
        }

        return $native;
    }

    /**
     * True when the signature return is an array whose element type is still the generic ptr slot
     * (from `function foo(): array` with no generics in PHP).
     */
    private function shouldRefineArrayReturnTypeWithPhpDoc(PicoType $native): bool
    {
        if (!$native->isArray()) {
            return false;
        }
        $el = $native->getElementType();

        return $el->toBase() === BaseType::PTR && !$el->isObject() && !$el->isMixed();
    }

    private function typeFromNode(\PhpParser\Node\Identifier|\PhpParser\Node\NullableType|\PhpParser\Node\Name|\PhpParser\Node\UnionType|\PhpParser\Node\IntersectionType $node): PicoType
    {
        if ($node instanceof \PhpParser\Node\IntersectionType) {
            return $this->resolveIntersectionType($node); // @codeCoverageIgnore
        }
        if ($node instanceof \PhpParser\Node\UnionType) {
            return $this->resolveUnionType($node);
        }
        if ($node instanceof \PhpParser\Node\NullableType) {
            $innerName = $node->type instanceof \PhpParser\Node\Name
                ? ClassSymbol::fqcnFromResolvedName($node->type, $this->currentNamespace())
                : $node->type->name;
            return PicoType::fromString('?' . $innerName);
        }
        if ($node instanceof \PhpParser\Node\Name) {
            $name = ClassSymbol::fqcnFromResolvedName($node, $this->currentNamespace());
            if ($name === 'Lexer') {
            }
            if (isset($this->enumRegistry[$name])) {
                return PicoType::enum($name);
            }
            return PicoType::fromString($name);
        }
        if (!in_array($node->name, ['int', 'float', 'bool', 'string', 'void', 'mixed', 'array', 'callable', 'iterable', 'object', 'null', 'self', 'static', 'parent', 'never'], true)) {
        }
        return PicoType::fromString($node->name);
    }

    /**
     * PHP 8.1+ {@code A&B}. PicoHP has no intersection types; use the first conjunct for symbols and checks.
     */
    private function resolveIntersectionType(\PhpParser\Node\IntersectionType $node): PicoType
    {
        \App\PicoHP\CompilerInvariant::check($node->types !== []); // @codeCoverageIgnore
        $first = $node->types[0]; // @codeCoverageIgnore

        return $this->typeFromNode($first); // @codeCoverageIgnore
    }

    private function resolveUnionType(\PhpParser\Node\UnionType $node): PicoType
    {
        $types = [];
        foreach ($node->types as $type) {
            $types[] = $this->typeFromNode($type);
        }

        // Widen int|float to float
        $bases = array_map(fn (PicoType $t) => $t->toBase(), $types);
        if (in_array(BaseType::FLOAT, $bases, true) && in_array(BaseType::INT, $bases, true)) {
            return PicoType::fromString('float');
        }

        // For other unions, use the first type as a fallback
        return $types[0]; // @codeCoverageIgnore
    }

    /**
     * @param array<\PhpParser\Node> $stmts
     */
    public function resolveStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            \App\PicoHP\CompilerInvariant::check($stmt instanceof \PhpParser\Node\Stmt);
            $this->resolveStmt($stmt);
        }
    }

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = $this->getPicoData($stmt);

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $returnType = $this->resolveTopLevelFunctionReturnPicoType($stmt);
            $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
            $pData->symbol = $existing ?? $this->symbolTable->addSymbol($stmt->name->name, $returnType, func: true);
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->symbolTable->enterScope());
            }

            $fnDoc = $stmt->getDocComment() !== null ? $stmt->getDocComment()->getText() : null;
            [$paramTypes, $defaults, $paramNames] = $this->resolveParams($stmt->params, $fnDoc);
            $pData->getSymbol()->params = $paramTypes;
            $pData->getSymbol()->defaults = $defaults;
            $pData->getSymbol()->paramNames = $paramNames;
            $previousReturnType = $this->currentFunctionReturnType;
            $previousFunctionSymbol = $this->currentFunctionSymbol;
            $this->currentFunctionReturnType = $returnType;
            $this->currentFunctionSymbol = $pData->getSymbol();
            try {
                $this->resolveStmts($stmt->stmts);
            } catch (\Exception $e) {
                if ($this->allowStubbing) {
                    $pData->stubbed = true;
                    $this->emitSemanticWarning('function body stubbed (will abort at runtime): ' . $e->getMessage(), $stmt);
                } else {
                    throw $e;
                }
            }
            $this->currentFunctionReturnType = $previousReturnType;
            $this->currentFunctionSymbol = $previousFunctionSymbol;

            if ($stmt->name->name !== 'main') {
                $this->symbolTable->exitScope();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $pData->setScope($this->symbolTable->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->symbolTable->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $type = $this->resolveExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $exprType = $this->resolveExpr($stmt->expr);
                $returnTypeOk = $this->currentFunctionReturnType === null
                    || $exprType->isEqualTo($this->currentFunctionReturnType)
                    || $this->isAssignmentCompatible($this->currentFunctionReturnType, $exprType)
                    || ($this->currentFunctionReturnType->isNullable() && $stmt->expr instanceof \PhpParser\Node\Expr\ConstFetch && $stmt->expr->name->toLowerString() === 'null');
                if (!$returnTypeOk) {
                    if ($exprType->isMixed() || $exprType->toBase() === BaseType::VOID) {
                        $this->emitSemanticWarning('return type coercion: ' . $exprType->toString() . ' → ' . $this->currentFunctionReturnType->toString() . ' (PHPStan validates at source level)', $stmt);
                    } else {
                        throw new \Exception(AstContextFormatter::location($stmt) . ', return type mismatch: expected ' . $this->currentFunctionReturnType->toString() . ', got ' . $exprType->toString());
                    }
                }
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Declare_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $this->resolveExpr($expr);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\If_) {
            $this->resolveExpr($stmt->cond);
            $this->resolveStmts($stmt->stmts);
            foreach ($stmt->elseifs as $elseif) {
                $this->resolveExpr($elseif->cond);
                $this->resolveStmts($elseif->stmts);
            }
            if (!is_null($stmt->else)) {
                $this->resolveStmts($stmt->else->stmts);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\While_) {
            $this->resolveExpr($stmt->cond);
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Do_) {
            $this->resolveStmts($stmt->stmts);
            $this->resolveExpr($stmt->cond);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\For_) {
            foreach ($stmt->init as $init) {
                $this->resolveExpr($init, $init->getDocComment());
            }
            foreach ($stmt->cond as $cond) {
                $condType = $this->resolveExpr($cond);
                \App\PicoHP\CompilerInvariant::check($condType->toBase() === BaseType::BOOL);
            }
            foreach ($stmt->loop as $loop) {
                $this->resolveExpr($loop);
            }
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Switch_) {
            $this->resolveExpr($stmt->cond);
            foreach ($stmt->cases as $case) {
                if ($case->cond !== null) {
                    $this->resolveExpr($case->cond);
                }
                $this->resolveStmts($case->stmts);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Break_) {
            // Break handled by IR gen
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Continue_) {
            // Continue handled by IR gen
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
            // Class constants handled during class registration
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            $arrayType = $this->resolveExpr($stmt->expr);
            if (!$arrayType->isArray() && !$arrayType->isMixed() && !$arrayType->isObject()) {
                throw new \Exception("foreach expression must be an array, got {$arrayType->toString()}");
            }
            \App\PicoHP\CompilerInvariant::check($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
            \App\PicoHP\CompilerInvariant::check(is_string($stmt->valueVar->name));
            $valueVarPData = $this->getPicoData($stmt->valueVar);
            $elementType = ($arrayType->isMixed() || $arrayType->isObject()) ? PicoType::fromString('mixed') : $arrayType->getElementType();
            // Reuse existing symbol if already declared in this scope (e.g. multiple foreach
            // loops using the same variable). Note: does not re-type — safe because PHPStan-max
            // enforces compatible types at the source level for our compilation target.
            $existing = $this->symbolTable->lookupCurrentScope($stmt->valueVar->name);
            $valueVarPData->symbol = $existing ?? $this->symbolTable->addSymbol(
                $stmt->valueVar->name,
                $elementType
            );
            if ($stmt->keyVar !== null) {
                \App\PicoHP\CompilerInvariant::check($stmt->keyVar instanceof \PhpParser\Node\Expr\Variable);
                \App\PicoHP\CompilerInvariant::check(is_string($stmt->keyVar->name));
                $keyVarPData = $this->getPicoData($stmt->keyVar);
                $keyType = $arrayType->hasStringKeys() ? 'string' : 'int';
                $existingKey = $this->symbolTable->lookupCurrentScope($stmt->keyVar->name);
                $keyVarPData->symbol = $existingKey ?? $this->symbolTable->addSymbol(
                    $stmt->keyVar->name,
                    PicoType::fromString($keyType)
                );
            }
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            if ($stmt->name === null) {
                return; // @codeCoverageIgnore
            }
            $fqcn = ClassSymbol::fqcn($this->currentNamespace(), $stmt->name->toString());
            $classMeta = $this->classRegistry[$fqcn] ?? null;
            if ($classMeta === null) {
                // Class not pre-registered (e.g., only has static methods handled by ClassToFunctionVisitor)
                $classMeta = new ClassMetadata($fqcn); // @codeCoverageIgnore
                $this->classRegistry[$fqcn] = $classMeta; // @codeCoverageIgnore
                if (class_exists($fqcn, true) && !trait_exists($fqcn, false)) { // @codeCoverageIgnore
                    $ref = new \ReflectionClass($fqcn); // @codeCoverageIgnore
                    if (!$ref->isInterface()) { // @codeCoverageIgnore
                        $this->registerInstanceMethodsFromReflection($classMeta, $fqcn); // @codeCoverageIgnore
                        $this->registerInstancePropertiesFromReflection($classMeta, $fqcn); // @codeCoverageIgnore
                    }
                }
            }
            $previousClass = $this->currentClass;
            $this->currentClass = $classMeta;
            $pData->setScope($this->symbolTable->enterScope());
            // Add $this to the class scope
            $this->symbolTable->addSymbol('this', PicoType::object($fqcn));
            // Resolve methods (properties already registered in registerClasses)
            $this->resolveStmts($stmt->stmts);
            $this->symbolTable->exitScope();
            $this->currentClass = $previousClass;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            // Properties already registered in Class_ handler
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
            \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
            $methodName = $stmt->name->toString();
            // Abstract methods have no body — already registered in registerClasses
            if ($stmt->stmts === null) {
                return;
            }
            \App\PicoHP\CompilerInvariant::check($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType || $stmt->returnType instanceof \PhpParser\Node\IntersectionType || $stmt->returnType === null);
            $returnType = $this->resolveMethodReturnTypeFromClassMethodNode($stmt);
            $methodSymbol = $this->symbolTable->addSymbol($methodName, $returnType, func: true);
            $pData->symbol = $methodSymbol;
            $this->currentClass->methods[$methodName] = $methodSymbol;
            $this->currentClass->methodOwner[$methodName] = $this->currentClass->name;
            $pData->setScope($this->symbolTable->enterScope());
            // Add $this to method scope
            $this->symbolTable->addSymbol('this', PicoType::object($this->currentClass->name));
            $methodDoc = $stmt->getDocComment() !== null ? $stmt->getDocComment()->getText() : null;
            [$paramTypes, $defaults, $paramNames] = $this->resolveParams($stmt->params, $methodDoc);
            $methodSymbol->params = $paramTypes;
            $methodSymbol->defaults = $defaults;
            $methodSymbol->paramNames = $paramNames;
            $previousReturnType = $this->currentFunctionReturnType;
            $previousFunctionSymbol = $this->currentFunctionSymbol;
            $this->currentFunctionReturnType = $returnType;
            $this->currentFunctionSymbol = $methodSymbol;
            try {
                $this->resolveStmts($stmt->stmts);
            } catch (\Exception $e) {
                if ($this->allowStubbing) {
                    $pData->stubbed = true;
                    $this->emitSemanticWarning('method body stubbed (will abort at runtime): ' . $e->getMessage(), $stmt);
                } else {
                    throw $e;
                }
            }
            $this->currentFunctionReturnType = $previousReturnType;
            $this->currentFunctionSymbol = $previousFunctionSymbol;
            $this->symbolTable->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
            // Enum cases already registered in registerClasses
            // Process enum methods
            // Enum cases already registered in registerClasses; enum methods not yet supported
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\EnumCase) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\GroupUse) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\TryCatch) {
            $this->resolveStmts($stmt->stmts);
            foreach ($stmt->catches as $catch) {
                \App\PicoHP\CompilerInvariant::check(count($catch->types) > 0);
                $catchTypeName = ClassSymbol::fqcnFromResolvedName($catch->types[0], $this->currentNamespace());
                $this->ensureExternalClassReference($catchTypeName);
                \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$catchTypeName]), "catch class {$catchTypeName} not found");
                if ($catch->var !== null) {
                    \App\PicoHP\CompilerInvariant::check(is_string($catch->var->name));
                    $catchPData = $this->getPicoData($catch->var);
                    $existing = $this->symbolTable->lookupCurrentScope($catch->var->name);
                    if ($existing !== null) {
                        $catchPData->symbol = $existing;
                    } else {
                        $catchPData->symbol = $this->symbolTable->addSymbol(
                            $catch->var->name,
                            PicoType::object($catchTypeName)
                        );
                    }
                }
                $this->resolveStmts($catch->stmts);
            }
            if ($stmt->finally !== null) {
                $this->resolveStmts($stmt->finally->stmts);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
            // Traits are inlined into classes during registration; skip
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
            $childNs = $stmt->name !== null ? $stmt->name->toString() : '';
            $merged = $childNs === '' ? null : $childNs;
            $this->pushNamespace($merged);
            try {
                $this->resolveStmts($stmt->stmts);
            } finally {
                $this->popNamespace();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\InlineHTML) {
            // TODO: create string constant?
        } else {
            throw new \Exception(AstContextFormatter::location($stmt) . ', unknown node type in stmt resolver: ' . get_class($stmt));
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null, bool $lVal = false, ?PicoType $rType = null): PicoType
    {
        $pData = $this->getPicoData($expr);

        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $rtype = $this->resolveExpr($expr->expr);
            // List/array destructuring: [$a, $b] = $arr
            if ($expr->var instanceof \PhpParser\Node\Expr\List_
                || ($expr->var instanceof \PhpParser\Node\Expr\Array_)) {
                $items = $expr->var instanceof \PhpParser\Node\Expr\List_
                    ? $expr->var->items
                    : $expr->var->items; // @codeCoverageIgnore
                $elementType = $rtype->isArray() ? $rtype->getElementType()
                    : ($rtype->isMixed() ? PicoType::fromString('mixed') : $rtype);
                foreach ($items as $item) {
                    /** @phpstan-ignore-next-line — items can be null for skipped positions */
                    if ($item !== null && $item->value !== null) {
                        $this->resolveExpr($item->value, $item->value->getDocComment(), lVal: true, rType: $elementType);
                    }
                }
                return $rtype;
            }
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true, rType: $rtype);
            $compatible = $ltype->isEqualTo($rtype)
                || $this->isAssignmentCompatible($ltype, $rtype);
            \App\PicoHP\CompilerInvariant::check($compatible, AstContextFormatter::location($expr) . ', type mismatch in assignment: ' . $ltype->toString() . ' = ' . $rtype->toString());
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Concat) {
            $this->resolveExpr($expr->expr);
            $this->resolveExpr($expr->var, $doc, lVal: true);
            return PicoType::fromString('string');
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus
            || $expr instanceof \PhpParser\Node\Expr\AssignOp\Minus) {
            $rtype = $this->resolveExpr($expr->expr);
            if ($expr->var instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                \App\PicoHP\CompilerInvariant::check(
                    $expr->var->dim !== null,
                    AstContextFormatter::location($expr) . ', compound assignment does not support empty [] push'
                );
            }
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true);
            \App\PicoHP\CompilerInvariant::check(
                $ltype->isEqualTo($rtype),
                AstContextFormatter::location($expr) . ', type mismatch in compound assignment: ' . $ltype->toString() . ' ' . ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus ? '+=' : '-=') . ' ' . $rtype->toString()
            );

            return $ltype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $pData->lVal = $lVal;
            \App\PicoHP\CompilerInvariant::check(is_string($expr->name));
            // Compile-time stub only: no real superglobals. Index reads (e.g. $_SERVER['argv']) lower to null.
            if ($expr->name === '_SERVER') {
                return PicoType::serverSuperglobalEmptyArray(); // @codeCoverageIgnore
            }
            $s = $this->symbolTable->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                $type = $this->docTypeParser->parseType($doc->getText());
                // @var Enum types are parsed as object(Name) — promote to enum if in registry
                if ($type->isObject() && isset($this->enumRegistry[$type->getClassName()])) {
                    $type = PicoType::enum($type->getClassName());
                }
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $type);
                return $type;
            } elseif (!is_null($rType) && is_null($s)) {
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $rType);
                return $rType;
            }

            $pData->symbol = $this->symbolTable->lookup($expr->name);
            \App\PicoHP\CompilerInvariant::check(!is_null($pData->symbol), AstContextFormatter::location($expr) . ', symbol not found: ' . $expr->name);
            return $pData->symbol->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $pData->lVal = $lVal;
            $type = $this->resolveExpr($expr->var, $doc, lVal: $lVal);
            if ($type->isArray() || $type->isMixed()) {
                if ($expr->dim !== null) {
                    $dimType = $this->resolveExpr($expr->dim);
                    if (!$type->isMixed() && $type->hasStringKeys()) {
                        \App\PicoHP\CompilerInvariant::check($dimType->isEqualTo(PicoType::fromString('string')), "{$dimType->toString()} is not a string for string-keyed array");
                    }
                }
                // dim === null means $arr[] = ... (push), resolved at Assign
                if ($type->isMixed()) {
                    return PicoType::fromString('mixed');
                }
                return $type->getElementType();
            }
            // string indexing — or unrecognized type (e.g. int class constant used as array)
            if (!$type->isEqualTo(PicoType::fromString('string'))) {
                $this->emitSemanticWarning('ArrayDimFetch on ' . $type->toString() . ' (expected string or array) — treating as mixed', $expr);
                return PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($expr->dim !== null); // @codeCoverageIgnore
            $dimType = $this->resolveExpr($expr->dim); // @codeCoverageIgnore
            \App\PicoHP\CompilerInvariant::check($dimType->isEqualTo(PicoType::fromString('int')), "{$dimType->toString()} is not an int"); // @codeCoverageIgnore
            return PicoType::fromString('int'); // @codeCoverageIgnore
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $this->resolveExpr($expr->left);
            return $this->resolveExpr($expr->right);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $sigil = $expr->getOperatorSigil();
            // PHP `.` coerces both operands to string — do not require equal types.
            if ($sigil === '.') {
                $this->resolveExpr($expr->left);
                $this->resolveExpr($expr->right);

                return PicoType::fromString('string');
            }
            $ltype = $this->resolveExpr($expr->left);
            $rtype = $this->resolveExpr($expr->right);
            // === and !== can compare different types (that's the point of strict comparison)
            if ($sigil !== '===' && $sigil !== '!==') {
                \App\PicoHP\CompilerInvariant::check($ltype->isEqualTo($rtype), AstContextFormatter::location($expr) . ', type mismatch in binary op: ' . $ltype->toString() . ' ' . $sigil . ' ' . $rtype->toString());
            }
            switch ($expr->getOperatorSigil()) {
                case '+':
                case '*':
                case '-':
                case '/':
                case '&':
                case '|':
                case '<<':
                case '>>':
                case '%':
                    $type = $rtype;
                    break;
                case '==':
                case '===':
                case '!=':
                case '!==':
                case '<':
                case '>':
                case '<=':
                case '>=':
                case '&&':
                case '||':
                    $type = PicoType::fromString('bool');
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$expr->getOperatorSigil()}");
            }
            return $type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            $type = $this->resolveExpr($expr->expr);
            \App\PicoHP\CompilerInvariant::check($type->isEqualTo(PicoType::fromString('int')));
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return PicoType::fromString('float');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            // TODO: add to symbol table?
            return PicoType::fromString('string');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\MagicConst\Dir) {
            return PicoType::fromString('string');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\MagicConst\File) {
            return PicoType::fromString('string');
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) { // @codeCoverageIgnore
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) { // @codeCoverageIgnore
                    // TODO: add string part to symbol table
                } else {
                    return $this->resolveExpr($part); // @codeCoverageIgnore
                }
            }
            return PicoType::fromString('string'); // @codeCoverageIgnore
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('float');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Bool_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\String_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('string');
        } elseif ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null); // @codeCoverageIgnore
                $className = $this->currentClass->name; // @codeCoverageIgnore
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            if (isset($this->enumRegistry[$className])) {
                return PicoType::enum($className);
            }
            // Class constants — assume int for now
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            return $this->picoTypeForConstFetchName($expr->name, $expr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            $argTypes = $this->resolveArgs($expr->args);
            // Built-in functions — registry-driven lookup
            if ($this->builtinRegistry->has($funcName)) {
                $def = $this->builtinRegistry->get($funcName);
                $pData->symbol = new \App\PicoHP\SymbolTable\Symbol($funcName, $def->returnType, func: true);
                if ($def->returnMatchesArg !== null) {
                    $argIdx = $def->returnMatchesArg;
                    if (count($expr->args) > $argIdx && $expr->args[$argIdx] instanceof \PhpParser\Node\Arg) {
                        return $this->resolveExpr($expr->args[$argIdx]->value);
                    }
                    return $def->returnType;
                }
                if ($def->returnElementType !== null) {
                    $argIdx = $def->returnElementType;
                    if (count($expr->args) > $argIdx && $expr->args[$argIdx] instanceof \PhpParser\Node\Arg) {
                        $arrType = $this->resolveExpr($expr->args[$argIdx]->value);
                        if ($arrType->isArray()) {
                            return $arrType->getElementType();
                        }
                    }
                    return PicoType::fromString('mixed');
                }
                return $def->returnType;
            }
            $funcNameRaw = $expr->name->name;
            $s = $this->symbolTable->lookup($funcNameRaw);
            if ($s === null) {
                // Unknown function — register a stub that returns mixed.
                // IR gen emits abort() if the stub is ever called at runtime.
                $this->emitSemanticWarning('unknown function ' . $funcNameRaw . ' — stub registered (will abort at runtime if called)', $expr);
                $s = $this->symbolTable->addSymbol($funcNameRaw, PicoType::fromString('mixed'), func: true);
            }
            $pData->symbol = $s;
            return $s->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Include_) {
            //$this->resolveExpr($expr->expr);
            return PicoType::fromString('void'); // @codeCoverageIgnore
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostInc) {
            return $this->resolveExpr($expr->var);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostDec) {
            return $this->resolveExpr($expr->var);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BooleanNot) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreInc) {
            return $this->resolveExpr($expr->var);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreDec) {
            return $this->resolveExpr($expr->var);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Array_) {
            // Empty `[]` has no element type until items exist; defaulting to string breaks `$xs[] = new SomeClass`.
            $elemType = PicoType::fromString('mixed');
            $hasStringKeys = false;
            $first = true;
            foreach ($expr->items as $item) {
                if ($item->key !== null) {
                    $keyType = $this->resolveExpr($item->key);
                    if ($keyType->isEqualTo(PicoType::fromString('string'))) {
                        $hasStringKeys = true;
                    }
                }
                $itemType = $this->resolveExpr($item->value);
                if ($first) {
                    $elemType = $itemType;
                    $first = false;
                }
            }
            $arrType = PicoType::array($elemType);
            if ($hasStringKeys) {
                $arrType->setStringKeys();
            }
            return $arrType;
        } elseif ($expr instanceof \PhpParser\Node\Expr\New_) {
            if ($expr->class instanceof \PhpParser\Node\Stmt\Class_) {
                $this->resolveArgs($expr->args);

                return PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self' || $rawClass === 'static') {
                if ($this->currentClass === null) {
                    $this->emitSemanticWarning('new self/static outside class context (hoisted method?) — treating as mixed', $expr);
                    $this->resolveArgs($expr->args);
                    return PicoType::fromString('mixed');
                }
                $className = $this->currentClass->name;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $this->ensureExternalClassReference($className);
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), AstContextFormatter::location($expr) . ", class {$className} not found");
            $this->resolveArgs($expr->args);
            return PicoType::object($className);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            $pData->lVal = $lVal;
            $objType = $this->resolveExpr($expr->var);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            // Enum ->value access
            if ($objType->isEnum() && $expr->name->toString() === 'value') {
                $enumMeta = $this->enumRegistry[$objType->getClassName()];
                if ($enumMeta->backingType === 'string') {
                    return PicoType::fromString('string');
                }
                return PicoType::fromString('int');
            }
            if ($objType->isMixed()) {
                return PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($objType->isObject(), AstContextFormatter::location($expr) . ', property fetch on non-object type: ' . $objType->toString());
            $className = $objType->getClassName();
            $this->ensureExternalClassReference($className);
            if (!isset($this->classRegistry[$className])) {
                if ($this->allowStubbing) {
                    $this->emitSemanticWarning('class ' . $className . ' not found in registry for property fetch', $expr);
                    return PicoType::fromString('mixed');
                }
                throw new \Exception(AstContextFormatter::location($expr) . ', class ' . $className . ' not found in registry for property fetch');
            }
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            $line = $expr->getStartLine();
            // Interface/abstract property access: resolve through descendants
            if (!isset($classMeta->properties[$propName])) {
                foreach ($this->classRegistry as $implMeta) {
                    if ($this->isDescendantOf($implMeta, $className) && isset($implMeta->properties[$propName])) {
                        return $implMeta->getPropertyType($propName, $line);
                    }
                }
            }
            $propResult = $classMeta->getPropertyType($propName, $line);
            if ($propName === 'lexer' && $propResult->isObject()) {
            }
            return $propResult;
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            $objType = $this->resolveExpr($expr->var);
            // Mixed type: method calls resolve to mixed
            if ($objType->isMixed()) {
                $this->resolveArgs($expr->args);
                return PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($objType->isObject(), "method call on non-object type: {$objType->toString()}");
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $objType->getClassName();
            $methodName = $expr->name->toString();
            if ($className === 'Lexer' && $methodName === 'tokenize') {
                // Trace where this short name comes from
                $sf = $expr->getAttribute('pico_source_file') ?? 'unknown';
                // Check what the lexer property type actually is in the registry
                $thisClassName = $this->currentClass !== null ? $this->currentClass->name : 'N/A';
                if ($this->currentClass !== null && isset($this->currentClass->properties['lexer'])) {
                    $lexerType = $this->currentClass->properties['lexer'];
                }
            }
            $this->ensureExternalClassReference($className);
            if (!isset($this->classRegistry[$className])) {
                if ($this->allowStubbing) {
                    $this->emitSemanticWarning("class {$className} not found in registry for method call {$methodName}", $expr);
                    $this->resolveArgs($expr->args);
                    return PicoType::fromString('mixed');
                }
                throw new \Exception("class {$className} not found in registry for method call {$methodName}");
            }
            $classMeta = $this->classRegistry[$className];
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->methods[$methodName]), "method {$methodName} not found on class {$className}");
            $this->resolveArgs($expr->args);
            return $classMeta->methods[$methodName]->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            $pData->lVal = $lVal;
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self' || $rawClass === 'static') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null); // @codeCoverageIgnore
                $className = $this->currentClass->name; // @codeCoverageIgnore
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $this->ensureExternalClassReference($className);
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "class {$className} not found");
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->staticProperties[$propName]), "static property {$propName} not found on {$className}");
            return $classMeta->staticProperties[$propName];
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            if (!($expr->class instanceof \PhpParser\Node\Name)) {
                $this->resolveExpr($expr->class);
                if ($expr->name instanceof \PhpParser\Node\Identifier) {
                    $this->resolveArgs($expr->args);
                } else {
                    $this->resolveExpr($expr->name); // @codeCoverageIgnore
                    $this->resolveArgs($expr->args); // @codeCoverageIgnore
                }
                $this->emitSemanticWarning('Dynamic static call ($expr::method()) — not supported by codegen; typed as mixed', $expr);

                return PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $rawClass = $expr->class->toString();
            $methodName = $expr->name->toString();
            $this->resolveArgs($expr->args);
            if ($rawClass === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null); // @codeCoverageIgnore
                $className = $this->currentClass->name; // @codeCoverageIgnore
            } elseif ($rawClass === 'parent') {
                $className = 'parent';
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace()); // @codeCoverageIgnore
            }
            if ($className === 'parent') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
                \App\PicoHP\CompilerInvariant::check($this->currentClass->parentName !== null, "parent:: used but class has no parent");
                $parentMeta = $this->classRegistry[$this->currentClass->parentName];
                \App\PicoHP\CompilerInvariant::check(isset($parentMeta->methods[$methodName]), "method {$methodName} not found on parent {$this->currentClass->parentName}");
                return $parentMeta->methods[$methodName]->type;
            }
            // Regular static call — resolve class
            $this->ensureExternalClassReference($className); // @codeCoverageIgnore
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "class {$className} not found"); // @codeCoverageIgnore
            $classMeta = $this->classRegistry[$className]; // @codeCoverageIgnore
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->methods[$methodName]), "method {$methodName} not found on {$className}"); // @codeCoverageIgnore
            return $classMeta->methods[$methodName]->type; // @codeCoverageIgnore
        } elseif ($expr instanceof \PhpParser\Node\Expr\Match_) {
            $this->resolveExpr($expr->cond);
            $resultType = null;
            foreach ($expr->arms as $arm) {
                if ($arm->conds !== null) {
                    foreach ($arm->conds as $cond) {
                        $this->resolveExpr($cond);
                    }
                }
                $bodyType = $this->resolveExpr($arm->body);
                if ($resultType === null) {
                    $resultType = $bodyType;
                }
            }
            \App\PicoHP\CompilerInvariant::check($resultType !== null);
            return $resultType;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Ternary) {
            $this->resolveExpr($expr->cond);
            if ($expr->if !== null) {
                $this->resolveExpr($expr->if);
            }
            return $this->resolveExpr($expr->else);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Isset_) {
            foreach ($expr->vars as $var) {
                $this->resolveExpr($var);
            }
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Instanceof_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Empty_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Exit_) {
            // PicoHP lowers exit()/die() to an early return from the current function (LLVM ret), not process
            // termination. Callers after exit() in the same PHP function are still reachable in the binary;
            // this matches self-hosting / dead-code patterns but diverges from PHP's real exit semantics.
            if ($expr->expr !== null) {
                $exprType = $this->resolveExpr($expr->expr);
                if ($this->currentFunctionReturnType !== null) {
                    $returnTypeOk = $exprType->isEqualTo($this->currentFunctionReturnType)
                        || $this->isAssignmentCompatible($this->currentFunctionReturnType, $exprType)
                        || ($this->currentFunctionReturnType->isNullable() && $expr->expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->expr->name->toLowerString() === 'null');
                    if (!$returnTypeOk) {
                        throw new \Exception(AstContextFormatter::location($expr) . ', exit value type mismatch: expected ' . $this->currentFunctionReturnType->toString() . ', got ' . $exprType->toString());
                    }
                }
            }

            return PicoType::fromString('void'); // @codeCoverageIgnore
        } elseif ($expr instanceof \PhpParser\Node\Expr\Throw_) {
            $this->resolveExpr($expr->expr);
            // Mark the enclosing function as throwing
            if ($this->currentFunctionSymbol !== null) {
                $this->currentFunctionSymbol->canThrow = true;
            }
            return PicoType::fromString('void');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Closure || $expr instanceof \PhpParser\Node\Expr\ArrowFunction) {
            $this->emitSemanticWarning('closure/arrow function not supported — treating as mixed (will abort at runtime)', $expr);
            return PicoType::fromString('mixed');
        } else {
            throw new \Exception(AstContextFormatter::location($expr) . ', unknown node type in expr resolver: ' . get_class($expr));
        }
    }

    /**
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     * @return array<PicoType>
     */
    public function resolveArgs(array $args): array
    {
        $argTypes = [];
        foreach ($args as $arg) {
            \App\PicoHP\CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
            $argTypes[] = $this->resolveExpr($arg->value);
        }
        return $argTypes;
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     * @return array{0: array<PicoType>, 1: array<int, \PhpParser\Node\Expr|null>, 2: array<int, string>}
     */
    public function resolveParams(array $params, ?string $methodDocBlock = null): array
    {
        $paramTypes = [];
        $defaults = [];
        $paramNames = [];
        $index = 0;
        foreach ($params as $param) {
            $pData = $this->getPicoData($param);
            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable);
            \App\PicoHP\CompilerInvariant::check(is_string($param->var->name));
            if ($param->type === null) {
                $this->emitSemanticWarning('untyped parameter $' . $param->var->name . ' — treating as mixed', $param);
                $paramType = PicoType::fromString('mixed');
            } elseif ($param->type instanceof \PhpParser\Node\IntersectionType) {
                $this->emitSemanticWarning('intersection type parameter $' . $param->var->name . ' — treating as mixed', $param);
                $paramType = PicoType::fromString('mixed');
            } else {
                \App\PicoHP\CompilerInvariant::check($param->type instanceof \PhpParser\Node\Identifier || $param->type instanceof \PhpParser\Node\NullableType || $param->type instanceof \PhpParser\Node\Name || $param->type instanceof \PhpParser\Node\UnionType, AstContextFormatter::location($param) . ', unsupported param type node: ' . get_class($param->type));
                $paramType = $this->typeFromNode($param->type);
            }
            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
            $varName = $param->var->name;
            if ($methodDocBlock !== null && $param->type instanceof \PhpParser\Node\Identifier && $param->type->name === 'array') {
                $fromDoc = $this->docTypeParser->parseParamTypeByName($methodDocBlock, $varName);
                if ($fromDoc !== null) {
                    $paramType = $fromDoc;
                }
            }
            $pData->symbol = $this->symbolTable->addSymbol($varName, $paramType);
            $paramTypes[] = $paramType;
            $defaults[$index] = $param->default;
            $paramNames[$index] = $varName;
            $index++;
        }
        return [$paramTypes, $defaults, $paramNames];
    }

    public function resolveProperty(\PhpParser\Node\Stmt\PropertyProperty $prop, PicoHPData $pData, PicoType $type): void
    {
        if ($prop->default !== null) { // @codeCoverageIgnore
            \App\PicoHP\CompilerInvariant::check($this->resolveExpr($prop->default) === $type); // @codeCoverageIgnore
        }
        $pData->symbol = $this->symbolTable->addSymbol($prop->name, $type); // @codeCoverageIgnore
    }

    protected function getPicoData(\PhpParser\Node $node): PicoHPData
    {
        if (!$node->hasAttribute("picoHP")) {
            $node->setAttribute("picoHP", new PicoHPData($this->symbolTable->getCurrentScope()));
        }
        return PicoHPData::getPData($node);
    }

    /**
     * Inferred type for {@see \PhpParser\Node\Expr\ConstFetch}. PHP magic constants used in
     * nikic/php-parser (e.g. {@code \PHP_VERSION_ID}) must match real PHP (int), not {@code bool}.
     *
     * Unknown constant names fall back to {@code int} and emit a semantic warning when a callback is
     * configured — typos are not hard errors so vendor code with edge-case constants can still compile.
     */
    private function picoTypeForConstFetchName(\PhpParser\Node\Name $name, ?\PhpParser\Node\Expr\ConstFetch $context = null): PicoType
    {
        $lower = $name->toLowerString();
        if ($lower === 'null') {
            return PicoType::fromString('string'); // null represented as ptr
        }
        if ($lower === 'true' || $lower === 'false') {
            return PicoType::fromString('bool');
        }
        if ($lower === 'php_version_id'
            || $lower === 'php_major_version'
            || $lower === 'php_minor_version'
            || $lower === 'php_release_version'
            || str_starts_with($lower, 'e_')) {
            return PicoType::fromString('int'); // @codeCoverageIgnore
        }
        if ($lower === 'php_version'
            || $lower === 'php_extra_version'
            || $lower === 'php_os'
            || $lower === 'php_os_family'
            || $lower === 'php_sapi'
            || $lower === 'php_eol') {
            return PicoType::fromString('string'); // @codeCoverageIgnore
        }
        // Standard streams — modeled as integer handles (Unix FDs: 0/1/2) for compiled I/O.
        if ($lower === 'stdin' || $lower === 'stdout' || $lower === 'stderr') {
            return PicoType::fromString('int');
        }
        if ($lower === 'debug_backtrace_ignore_args' || $lower === 'debug_backtrace_provide_object') {
            return PicoType::fromString('int'); // @codeCoverageIgnore
        }
        if ($lower === 'directory_separator') {
            return PicoType::fromString('string'); // @codeCoverageIgnore
        }

        if ($context !== null) {
            $this->emitSemanticWarning(
                'ConstFetch name not in known PHP magic constant list; inferred as int (verify spelling or extend picoTypeForConstFetchName)',
                $context,
            );
        }

        return PicoType::fromString('int');
    }
}

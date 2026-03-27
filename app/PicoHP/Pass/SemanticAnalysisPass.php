<?php

namespace App\PicoHP\Pass;

use App\PicoHP\{PassInterface, SymbolTable};
use App\PicoHP\SymbolTable\{ClassMetadata, DocTypeParser, EnumMetadata, PicoHPData};
use App\PicoHP\{BaseType, PicoType};

class SemanticAnalysisPass implements PassInterface
{
    /**
     * @var array<\PhpParser\Node>
     */
    protected array $ast;

    protected SymbolTable $symbolTable;
    protected DocTypeParser $docTypeParser;
    protected ?PicoType $currentFunctionReturnType = null;
    protected ?ClassMetadata $currentClass = null;

    /** @var array<string, ClassMetadata> */
    protected array $classRegistry = [];

    /** @var array<string, EnumMetadata> */
    protected array $enumRegistry = [];

    /** @var array<string, array{properties: array<\PhpParser\Node\Stmt\Property>, methods: array<\PhpParser\Node\Stmt\ClassMethod>}> */
    protected array $traitRegistry = [];

    /**
     * @param array<\PhpParser\Node> $ast
     */
    public function __construct(array $ast)
    {
        $this->ast = $ast;
        $this->symbolTable = new SymbolTable();
        $this->docTypeParser = new DocTypeParser();
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

    public function exec(): void
    {
        $this->registerBuiltinClasses();
        $this->registerClasses($this->ast);
        $this->registerFunctions($this->ast);
        $this->resolveStmts($this->ast);
    }

    protected function registerBuiltinClasses(): void
    {
        $exceptionMeta = new ClassMetadata('Exception');
        $exceptionMeta->addProperty('message', PicoType::fromString('string'));
        $getMessageSymbol = new \App\PicoHP\SymbolTable\Symbol('getMessage', PicoType::fromString('string'), func: true);
        $exceptionMeta->methods['getMessage'] = $getMessageSymbol;
        $exceptionMeta->methodOwner['getMessage'] = 'Exception';
        $ctorSymbol = new \App\PicoHP\SymbolTable\Symbol('__construct', PicoType::fromString('void'), func: true);
        $ctorSymbol->params = [PicoType::fromString('string')];
        $ctorSymbol->paramNames = [0 => 'message'];
        $exceptionMeta->methods['__construct'] = $ctorSymbol;
        $exceptionMeta->methodOwner['__construct'] = 'Exception';
        $this->classRegistry['Exception'] = $exceptionMeta;
        $this->typeIdMap['Exception'] = $this->nextTypeId++;
    }

    /**
     * Pre-pass: register class metadata (properties and method signatures).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerClasses(array $stmts): void
    {
        // Pre-pass: register all traits before processing classes
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $this->registerTraits($stmt->stmts);
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
                $this->registerTrait($stmt);
            }
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $this->registerClasses($stmt->stmts);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
                $this->registerClasses($stmt->stmts);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
                \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
                $className = $stmt->name->toString();
                $classMeta = new ClassMetadata($className);
                $classMeta->isAbstract = $stmt->isAbstract();
                $this->classRegistry[$className] = $classMeta;
                // Inherit from parent class
                if ($stmt->extends !== null) {
                    $parentName = $stmt->extends->toString();
                    \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$parentName]), "parent class {$parentName} not found");
                    $classMeta->inheritFrom($this->classRegistry[$parentName]);
                }
                // Assign type_id to all classes (used for virtual dispatch and exceptions)
                $this->typeIdMap[$className] = $this->nextTypeId++;
                // Track implemented interfaces
                foreach ($stmt->implements as $iface) {
                    $classMeta->interfaces[] = $iface->toString();
                }
                // Inline trait members into class AST
                $inlinedStmts = [];
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\TraitUse) {
                        foreach ($classStmt->traits as $traitName) {
                            $name = $traitName->toString();
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
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\Property) {
                        if ($classStmt->type === null) {
                            // No native type hint — require PHPDoc
                            $doc = $classStmt->getDocComment();
                            \App\PicoHP\CompilerInvariant::check($doc !== null, 'untyped property requires PHPDoc type annotation');
                            $propType = $this->docTypeParser->parseType($doc->getText());
                        } else {
                            \App\PicoHP\CompilerInvariant::check($classStmt->type instanceof \PhpParser\Node\Identifier || $classStmt->type instanceof \PhpParser\Node\NullableType || $classStmt->type instanceof \PhpParser\Node\Name || $classStmt->type instanceof \PhpParser\Node\UnionType);
                            $doc = $classStmt->getDocComment();
                            if ($doc !== null) {
                                $propType = $this->docTypeParser->parseType($doc->getText());
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
                        \App\PicoHP\CompilerInvariant::check($classStmt->returnType === null || $classStmt->returnType instanceof \PhpParser\Node\Identifier || $classStmt->returnType instanceof \PhpParser\Node\NullableType || $classStmt->returnType instanceof \PhpParser\Node\Name || $classStmt->returnType instanceof \PhpParser\Node\UnionType);
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
                        $classMeta->methodOwner[$methodName] = $className;
                    } elseif ($classStmt instanceof \PhpParser\Node\Stmt\ClassConst) {
                        foreach ($classStmt->consts as $const) {
                            if ($const->value instanceof \PhpParser\Node\Scalar\Int_) {
                                $classMeta->constants[$const->name->toString()] = $const->value->value;
                            }
                        }
                    }
                }
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
                \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
                $ifaceName = $stmt->name->toString();
                $ifaceMeta = new ClassMetadata($ifaceName);
                $this->classRegistry[$ifaceName] = $ifaceMeta;
                foreach ($stmt->stmts as $ifaceStmt) {
                    if ($ifaceStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $ifaceStmt->name->toString();
                        \App\PicoHP\CompilerInvariant::check($ifaceStmt->returnType === null || $ifaceStmt->returnType instanceof \PhpParser\Node\Identifier || $ifaceStmt->returnType instanceof \PhpParser\Node\NullableType || $ifaceStmt->returnType instanceof \PhpParser\Node\Name || $ifaceStmt->returnType instanceof \PhpParser\Node\UnionType);
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
                $enumName = $stmt->name->toString();
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
                        \App\PicoHP\CompilerInvariant::check($enumStmt->returnType === null || $enumStmt->returnType instanceof \PhpParser\Node\Identifier || $enumStmt->returnType instanceof \PhpParser\Node\NullableType || $enumStmt->returnType instanceof \PhpParser\Node\Name || $enumStmt->returnType instanceof \PhpParser\Node\UnionType);
                        $returnType = $enumStmt->returnType !== null
                            ? $this->typeFromNode($enumStmt->returnType)
                            : PicoType::fromString('void');
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $enumClassMeta->methods[$methodName] = $methodSymbol;
                        $enumClassMeta->methodOwner[$methodName] = $enumName;
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
            }
        }
    }

    /**
     * Pre-pass: register all traits from a list of statements.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    protected function registerTraits(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
                $this->registerTrait($stmt);
            }
        }
    }

    protected function registerTrait(\PhpParser\Node\Stmt\Trait_ $stmt): void
    {
        \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
        $traitName = $stmt->name->toString();
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
     * Pre-pass: register all top-level function declarations so they can be
     * referenced before their definition (forward references).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerFunctions(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
                \App\PicoHP\CompilerInvariant::check($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType);
                $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
                if ($existing === null) {
                    $sym = $this->symbolTable->addSymbol(
                        $stmt->name->name,
                        $this->typeFromNode($stmt->returnType),
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
            return false;
        }
        return $this->isSubclassOf($meta->parentName, $parentName);
    }

    /**
     * Check if rtype can be assigned to a variable/return of ltype.
     * Allows: interface to implementor, parent to child, nullable variants.
     */
    protected function isAssignmentCompatible(PicoType $ltype, PicoType $rtype): bool
    {
        // Both must be ptr-based (objects, nullable objects, arrays, strings)
        if ($ltype->toBase() !== BaseType::PTR || $rtype->toBase() !== BaseType::PTR) {
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
            $rmeta = $this->classRegistry[$rclass] ?? null;
            if ($rmeta !== null && in_array($lclass, $rmeta->interfaces, true)) {
                return true;
            }
            // rtype is an interface that ltype implements
            $lmeta = $this->classRegistry[$lclass] ?? null;
            if ($lmeta !== null && in_array($rclass, $lmeta->interfaces, true)) {
                return true;
            }
            // Both are in classRegistry (could be interface assigned from interface)
            if (isset($this->classRegistry[$lclass]) && isset($this->classRegistry[$rclass])) {
                return true;
            }
        }
        return false;
    }

    private function isDescendantOf(ClassMetadata $meta, string $ancestor): bool
    {
        if (in_array($ancestor, $meta->interfaces, true)) {
            return true;
        }
        $current = $meta->parentName;
        while ($current !== null) {
            if ($current === $ancestor) {
                return true;
            }
            $current = isset($this->classRegistry[$current]) ? $this->classRegistry[$current]->parentName : null;
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

    private function typeFromNode(\PhpParser\Node\Identifier|\PhpParser\Node\NullableType|\PhpParser\Node\Name|\PhpParser\Node\UnionType $node): PicoType
    {
        if ($node instanceof \PhpParser\Node\UnionType) {
            return $this->resolveUnionType($node);
        }
        if ($node instanceof \PhpParser\Node\NullableType) {
            $innerName = $node->type instanceof \PhpParser\Node\Name ? $node->type->toString() : $node->type->name;
            return PicoType::fromString('?' . $innerName);
        }
        if ($node instanceof \PhpParser\Node\Name) {
            $name = $node->toString();
            if (isset($this->enumRegistry[$name])) {
                return PicoType::enum($name);
            }
            return PicoType::fromString($name);
        }
        return PicoType::fromString($node->name);
    }

    private function resolveUnionType(\PhpParser\Node\UnionType $node): PicoType
    {
        $types = [];
        foreach ($node->types as $type) {
            \App\PicoHP\CompilerInvariant::check($type instanceof \PhpParser\Node\Identifier || $type instanceof \PhpParser\Node\Name);
            $types[] = $this->typeFromNode($type);
        }

        // Widen int|float to float
        $bases = array_map(fn (PicoType $t) => $t->toBase(), $types);
        if (in_array(BaseType::FLOAT, $bases, true) && in_array(BaseType::INT, $bases, true)) {
            return PicoType::fromString('float');
        }

        // For other unions, use the first type as a fallback
        return $types[0];
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
            \App\PicoHP\CompilerInvariant::check(!is_null($stmt->returnType));
            \App\PicoHP\CompilerInvariant::check($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType);
            $returnType = $this->typeFromNode($stmt->returnType);
            $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
            $pData->symbol = $existing ?? $this->symbolTable->addSymbol($stmt->name->name, $returnType, func: true);
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->symbolTable->enterScope());
            }

            [$paramTypes, $defaults, $paramNames] = $this->resolveParams($stmt->params);
            $pData->getSymbol()->params = $paramTypes;
            $pData->getSymbol()->defaults = $defaults;
            $pData->getSymbol()->paramNames = $paramNames;
            $previousReturnType = $this->currentFunctionReturnType;
            $this->currentFunctionReturnType = $returnType;
            $this->resolveStmts($stmt->stmts);
            $this->currentFunctionReturnType = $previousReturnType;

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
                    $line = $this->getLine($stmt);
                    throw new \Exception("line {$line}, return type mismatch: expected {$this->currentFunctionReturnType->toString()}, got {$exprType->toString()}");
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
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
            // Class constants handled during class registration
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            $arrayType = $this->resolveExpr($stmt->expr);
            \App\PicoHP\CompilerInvariant::check($arrayType->isArray() || $arrayType->isMixed(), "foreach expression must be an array, got {$arrayType->toString()}");
            \App\PicoHP\CompilerInvariant::check($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
            \App\PicoHP\CompilerInvariant::check(is_string($stmt->valueVar->name));
            $valueVarPData = $this->getPicoData($stmt->valueVar);
            $elementType = $arrayType->isMixed() ? PicoType::fromString('mixed') : $arrayType->getElementType();
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
            \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
            $className = $stmt->name->toString();
            $classMeta = $this->classRegistry[$className] ?? null;
            if ($classMeta === null) {
                // Class not pre-registered (e.g., only has static methods handled by ClassToFunctionVisitor)
                $classMeta = new ClassMetadata($className);
                $this->classRegistry[$className] = $classMeta;
            }
            $previousClass = $this->currentClass;
            $this->currentClass = $classMeta;
            $pData->setScope($this->symbolTable->enterScope());
            // Add $this to the class scope
            $this->symbolTable->addSymbol('this', PicoType::object($className));
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
            \App\PicoHP\CompilerInvariant::check($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType || $stmt->returnType === null);
            $returnType = $this->resolveMethodReturnTypeFromClassMethodNode($stmt);
            $methodSymbol = $this->symbolTable->addSymbol($methodName, $returnType, func: true);
            $pData->symbol = $methodSymbol;
            $this->currentClass->methods[$methodName] = $methodSymbol;
            $this->currentClass->methodOwner[$methodName] = $this->currentClass->name;
            $pData->setScope($this->symbolTable->enterScope());
            // Add $this to method scope
            $this->symbolTable->addSymbol('this', PicoType::object($this->currentClass->name));
            [$paramTypes, $defaults, $paramNames] = $this->resolveParams($stmt->params);
            $methodSymbol->params = $paramTypes;
            $methodSymbol->defaults = $defaults;
            $methodSymbol->paramNames = $paramNames;
            $previousReturnType = $this->currentFunctionReturnType;
            $this->currentFunctionReturnType = $returnType;
            $this->resolveStmts($stmt->stmts);
            $this->currentFunctionReturnType = $previousReturnType;
            $this->symbolTable->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
            // Enum cases already registered in registerClasses
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\EnumCase) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\TryCatch) {
            $this->resolveStmts($stmt->stmts);
            foreach ($stmt->catches as $catch) {
                \App\PicoHP\CompilerInvariant::check(count($catch->types) > 0);
                $catchTypeName = $catch->types[0]->toString();
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
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\InlineHTML) {
            // TODO: create string constant?
        } else {
            $line = $this->getLine($stmt);
            throw new \Exception("line: {$line}, unknown node type in stmt resolver: " . get_class($stmt));
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
                    : $expr->var->items;
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
            $line = $this->getLine($expr);
            $compatible = $ltype->isEqualTo($rtype)
                || $this->isAssignmentCompatible($ltype, $rtype);
            \App\PicoHP\CompilerInvariant::check($compatible, "line {$line}, type mismatch in assignment: {$ltype->toString()} = {$rtype->toString()}");
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus
            || $expr instanceof \PhpParser\Node\Expr\AssignOp\Minus) {
            $rtype = $this->resolveExpr($expr->expr);
            if ($expr->var instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                $line = $this->getLine($expr);
                \App\PicoHP\CompilerInvariant::check(
                    $expr->var->dim !== null,
                    "line {$line}, compound assignment does not support empty [] push"
                );
            }
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true);
            $line = $this->getLine($expr);
            \App\PicoHP\CompilerInvariant::check(
                $ltype->isEqualTo($rtype),
                "line {$line}, type mismatch in compound assignment: {$ltype->toString()} " . ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus ? '+=' : '-=') . " {$rtype->toString()}"
            );

            return $ltype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $pData->lVal = $lVal;
            \App\PicoHP\CompilerInvariant::check(is_string($expr->name));
            $s = $this->symbolTable->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                $type = $this->docTypeParser->parseType($doc->getText());
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $type);
                return $type;
            } elseif (!is_null($rType) && is_null($s)) {
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $rType);
                return $rType;
            }

            $pData->symbol = $this->symbolTable->lookup($expr->name);
            $line = $this->getLine($expr);
            \App\PicoHP\CompilerInvariant::check(!is_null($pData->symbol), "line {$line}, symbol not found: {$expr->name}");
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
            // string indexing
            \App\PicoHP\CompilerInvariant::check($type->isEqualTo(PicoType::fromString('string')), "{$type->toString()} is not a string or array");
            \App\PicoHP\CompilerInvariant::check($expr->dim !== null);
            $dimType = $this->resolveExpr($expr->dim);
            \App\PicoHP\CompilerInvariant::check($dimType->isEqualTo(PicoType::fromString('int')), "{$dimType->toString()} is not an int");
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $this->resolveExpr($expr->left);
            return $this->resolveExpr($expr->right);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $ltype = $this->resolveExpr($expr->left);
            $rtype = $this->resolveExpr($expr->right);
            $line = $this->getLine($expr);
            // === and !== can compare different types (that's the point of strict comparison)
            $sigil = $expr->getOperatorSigil();
            if ($sigil !== '===' && $sigil !== '!==') {
                \App\PicoHP\CompilerInvariant::check($ltype->isEqualTo($rtype), "line {$line}, type mismatch in binary op: {$ltype->toString()} {$sigil} {$rtype->toString()}");
            }
            switch ($expr->getOperatorSigil()) {
                case '.':
                    $type = PicoType::fromString('string');
                    break;
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
                case '??':
                    $type = $rtype; // result is the fallback type
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
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) {
                    // TODO: add string part to symbol table
                } else {
                    return $this->resolveExpr($part);
                }
            }
            return PicoType::fromString('string');
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
            $className = $expr->class->toString();
            if (isset($this->enumRegistry[$className])) {
                return PicoType::enum($className);
            }
            // Class constants — assume int for now
            return PicoType::fromString('int');
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            if ($expr->name->toLowerString() === 'null') {
                return PicoType::fromString('string'); // null represented as ptr
            }
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            $this->resolveArgs($expr->args);
            // Built-in functions
            if ($funcName === 'count' || $funcName === 'strlen') {
                return PicoType::fromString('int');
            }
            if ($funcName === 'str_starts_with' || $funcName === 'str_contains') {
                return PicoType::fromString('bool');
            }
            if ($funcName === 'strval') {
                return PicoType::fromString('string');
            }
            if ($funcName === 'implode' || $funcName === 'substr' || $funcName === 'trim' || $funcName === 'str_repeat' || $funcName === 'str_replace'
                || $funcName === 'strtoupper' || $funcName === 'strtolower' || $funcName === 'dechex' || $funcName === 'str_pad') {
                return PicoType::fromString('string');
            }
            if ($funcName === 'is_int' || $funcName === 'is_string' || $funcName === 'is_float' || $funcName === 'is_bool'
                || $funcName === 'array_key_exists') {
                return PicoType::fromString('bool');
            }
            if ($funcName === 'array_search') {
                return PicoType::fromString('int'); // returns index or false, simplified to int
            }
            if ($funcName === 'intval') {
                return PicoType::fromString('int');
            }
            if ($funcName === 'array_reverse') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->resolveExpr($expr->args[0]->value);
            }
            if ($funcName === 'array_pop' || $funcName === 'array_shift') {
                return PicoType::fromString('void');
            }
            if ($funcName === 'array_merge') {
                if (count($expr->args) >= 1 && $expr->args[0] instanceof \PhpParser\Node\Arg) {
                    return $this->resolveExpr($expr->args[0]->value);
                }
                return PicoType::fromString('array');
            }
            if ($funcName === 'assert') {
                return PicoType::fromString('void');
            }
            if ($funcName === 'preg_match') {
                return PicoType::fromString('int');
            }
            if ($funcName === 'end') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrType = $this->resolveExpr($expr->args[0]->value);
                \App\PicoHP\CompilerInvariant::check($arrType->isArray());
                return $arrType->getElementType();
            }
            if ($funcName === 'array_splice') {
                return PicoType::fromString('void');
            }
            $s = $this->symbolTable->lookup($expr->name->name);
            $line = $this->getLine($expr);
            \App\PicoHP\CompilerInvariant::check($s !== null, "line {$line}, function {$expr->name->name} not found");
            $pData->symbol = $s;
            return $s->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Include_) {
            //$this->resolveExpr($expr->expr);
            return PicoType::fromString('void');
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
            $elemType = PicoType::fromString('string'); // default
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
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            if ($className === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "class {$className} not found");
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
            $line = $this->getLine($expr);
            \App\PicoHP\CompilerInvariant::check($objType->isObject(), "line {$line}, property fetch on non-object type: {$objType->toString()}");
            $className = $objType->getClassName();
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "line {$line}, class {$className} not found in registry for property fetch");
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            // Interface/abstract property access: resolve through descendants
            if (!isset($classMeta->properties[$propName])) {
                foreach ($this->classRegistry as $implMeta) {
                    if ($this->isDescendantOf($implMeta, $className) && isset($implMeta->properties[$propName])) {
                        return $implMeta->getPropertyType($propName, $line);
                    }
                }
            }
            return $classMeta->getPropertyType($propName, $line);
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
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "class {$className} not found in registry for method call {$methodName}");
            $classMeta = $this->classRegistry[$className];
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->methods[$methodName]), "method {$methodName} not found on class {$className}");
            $this->resolveArgs($expr->args);
            return $classMeta->methods[$methodName]->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            $pData->lVal = $lVal;
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $className = $expr->class->toString();
            if ($className === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->staticProperties[$propName]), "static property {$propName} not found on {$className}");
            return $classMeta->staticProperties[$propName];
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $expr->class->toString();
            $methodName = $expr->name->toString();
            $this->resolveArgs($expr->args);
            if ($className === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            if ($className === 'parent') {
                \App\PicoHP\CompilerInvariant::check($this->currentClass !== null);
                \App\PicoHP\CompilerInvariant::check($this->currentClass->parentName !== null, "parent:: used but class has no parent");
                $parentMeta = $this->classRegistry[$this->currentClass->parentName];
                \App\PicoHP\CompilerInvariant::check(isset($parentMeta->methods[$methodName]), "method {$methodName} not found on parent {$this->currentClass->parentName}");
                return $parentMeta->methods[$methodName]->type;
            }
            // Regular static call — resolve class
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]), "class {$className} not found");
            $classMeta = $this->classRegistry[$className];
            \App\PicoHP\CompilerInvariant::check(isset($classMeta->methods[$methodName]), "method {$methodName} not found on {$className}");
            return $classMeta->methods[$methodName]->type;
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\Throw_) {
            $this->resolveExpr($expr->expr);
            return PicoType::fromString('void');
        } else {
            $line = $this->getLine($expr);
            throw new \Exception("line {$line}, unknown node type in expr resolver: " . get_class($expr));
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
    public function resolveParams(array $params): array
    {
        $paramTypes = [];
        $defaults = [];
        $paramNames = [];
        $index = 0;
        foreach ($params as $param) {
            $pData = $this->getPicoData($param);
            \App\PicoHP\CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable);
            \App\PicoHP\CompilerInvariant::check(is_string($param->var->name));
            \App\PicoHP\CompilerInvariant::check($param->type instanceof \PhpParser\Node\Identifier || $param->type instanceof \PhpParser\Node\NullableType || $param->type instanceof \PhpParser\Node\Name || $param->type instanceof \PhpParser\Node\UnionType);
            $paramType = $this->typeFromNode($param->type);
            $pData->symbol = $this->symbolTable->addSymbol($param->var->name, $paramType);
            $paramTypes[] = $paramType;
            $defaults[$index] = $param->default;
            $paramNames[$index] = $param->var->name;
            $index++;
        }
        return [$paramTypes, $defaults, $paramNames];
    }

    public function resolveProperty(\PhpParser\Node\Stmt\PropertyProperty $prop, PicoHPData $pData, PicoType $type): void
    {
        if ($prop->default !== null) {
            \App\PicoHP\CompilerInvariant::check($this->resolveExpr($prop->default) === $type);
        }
        $pData->symbol = $this->symbolTable->addSymbol($prop->name, $type);
    }

    protected function getLine(\PhpParser\Node $node): int
    {
        $line = 0;
        if ($node->hasAttribute("startLine")) {
            $line = $node->getAttribute("startLine");
            \App\PicoHP\CompilerInvariant::check(is_int($line));
        }
        return $line;
    }

    protected function getPicoData(\PhpParser\Node $node): PicoHPData
    {
        if (!$node->hasAttribute("picoHP")) {
            $node->setAttribute("picoHP", new PicoHPData($this->symbolTable->getCurrentScope()));
        }
        return PicoHPData::getPData($node);
    }
}

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

    public function getClassMetadata(string $name): ClassMetadata
    {
        assert(isset($this->classRegistry[$name]), "class {$name} not found");
        return $this->classRegistry[$name];
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
                assert($stmt->name instanceof \PhpParser\Node\Identifier);
                $className = $stmt->name->toString();
                $classMeta = new ClassMetadata($className);
                $this->classRegistry[$className] = $classMeta;
                // Inherit from parent class
                if ($stmt->extends !== null) {
                    $parentName = $stmt->extends->toString();
                    assert(isset($this->classRegistry[$parentName]), "parent class {$parentName} not found");
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
                            assert(isset($this->traitRegistry[$name]), "trait {$name} not found");
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
                            assert($doc !== null, 'untyped property requires PHPDoc type annotation');
                            $propType = $this->docTypeParser->parseType($doc->getText());
                        } else {
                            assert($classStmt->type instanceof \PhpParser\Node\Identifier || $classStmt->type instanceof \PhpParser\Node\NullableType || $classStmt->type instanceof \PhpParser\Node\Name || $classStmt->type instanceof \PhpParser\Node\UnionType);
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
                                $classMeta->addProperty($prop->name->toString(), $propType);
                            }
                        }
                    } elseif ($classStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $classStmt->name->toString();
                        assert($classStmt->returnType === null || $classStmt->returnType instanceof \PhpParser\Node\Identifier || $classStmt->returnType instanceof \PhpParser\Node\NullableType || $classStmt->returnType instanceof \PhpParser\Node\Name || $classStmt->returnType instanceof \PhpParser\Node\UnionType);
                        $returnType = $classStmt->returnType !== null
                            ? $this->typeFromNode($classStmt->returnType)
                            : PicoType::fromString('void');
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $pi = 0;
                        foreach ($classStmt->params as $param) {
                            $methodSymbol->defaults[$pi] = $param->default;
                            assert($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                            $methodSymbol->paramNames[$pi] = $param->var->name;
                            $pi++;
                        }
                        $classMeta->methods[$methodName] = $methodSymbol;
                        $classMeta->methodOwner[$methodName] = $className;
                    }
                }
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
                assert($stmt->name instanceof \PhpParser\Node\Identifier);
                $ifaceName = $stmt->name->toString();
                $ifaceMeta = new ClassMetadata($ifaceName);
                $this->classRegistry[$ifaceName] = $ifaceMeta;
                foreach ($stmt->stmts as $ifaceStmt) {
                    if ($ifaceStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $ifaceStmt->name->toString();
                        assert($ifaceStmt->returnType === null || $ifaceStmt->returnType instanceof \PhpParser\Node\Identifier || $ifaceStmt->returnType instanceof \PhpParser\Node\NullableType || $ifaceStmt->returnType instanceof \PhpParser\Node\Name || $ifaceStmt->returnType instanceof \PhpParser\Node\UnionType);
                        $returnType = $ifaceStmt->returnType !== null
                            ? $this->typeFromNode($ifaceStmt->returnType)
                            : PicoType::fromString('void');
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $pi = 0;
                        foreach ($ifaceStmt->params as $param) {
                            $methodSymbol->defaults[$pi] = $param->default;
                            assert($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                            $methodSymbol->paramNames[$pi] = $param->var->name;
                            $pi++;
                        }
                        $ifaceMeta->methods[$methodName] = $methodSymbol;
                        $ifaceMeta->methodOwner[$methodName] = $ifaceName;
                    }
                }
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
                assert($stmt->name instanceof \PhpParser\Node\Identifier);
                $enumName = $stmt->name->toString();
                $scalarTypeName = null;
                if ($stmt->scalarType !== null) {
                    $scalarTypeName = $stmt->scalarType->name;
                }
                $enumMeta = new EnumMetadata($enumName, $scalarTypeName);
                $this->enumRegistry[$enumName] = $enumMeta;
                foreach ($stmt->stmts as $enumStmt) {
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
        assert($stmt->name instanceof \PhpParser\Node\Identifier);
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
                assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType);
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
                        assert($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name));
                        $sym->paramNames[$pi] = $param->var->name;
                        $pi++;
                    }
                }
            }
        }
    }

    /**
     * Check if a class is an exception class (is or extends Exception).
     */
    protected function isExceptionClass(string $className): bool
    {
        if ($className === 'Exception') {
            return true;
        }
        $meta = $this->classRegistry[$className] ?? null;
        if ($meta === null) {
            return false;
        }
        if ($meta->parentName !== null) {
            return $this->isExceptionClass($meta->parentName);
        }
        return false;
    }

    /**
     * Get all type_ids that should match a catch clause for a given class.
     * Includes the class itself and all its descendants.
     *
     * @return array<int>
     */
    public function getMatchingTypeIds(string $className): array
    {
        $ids = [];
        foreach ($this->typeIdMap as $name => $id) {
            if ($this->isSubclassOf($name, $className)) {
                $ids[] = $id;
            }
        }
        return $ids;
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
            assert($type instanceof \PhpParser\Node\Identifier || $type instanceof \PhpParser\Node\Name);
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
            assert($stmt instanceof \PhpParser\Node\Stmt);
            $this->resolveStmt($stmt);
        }
    }

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = $this->getPicoData($stmt);

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            assert(!is_null($stmt->returnType));
            assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType);
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
                assert($condType->toBase() === BaseType::BOOL);
            }
            foreach ($stmt->loop as $loop) {
                $this->resolveExpr($loop);
            }
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            $arrayType = $this->resolveExpr($stmt->expr);
            assert($arrayType->isArray(), "foreach expression must be an array, got {$arrayType->toString()}");
            assert($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
            assert(is_string($stmt->valueVar->name));
            $valueVarPData = $this->getPicoData($stmt->valueVar);
            $valueVarPData->symbol = $this->symbolTable->addSymbol(
                $stmt->valueVar->name,
                $arrayType->getElementType()
            );
            if ($stmt->keyVar !== null) {
                assert($stmt->keyVar instanceof \PhpParser\Node\Expr\Variable);
                assert(is_string($stmt->keyVar->name));
                $keyVarPData = $this->getPicoData($stmt->keyVar);
                $keyType = $arrayType->hasStringKeys() ? 'string' : 'int';
                $keyVarPData->symbol = $this->symbolTable->addSymbol(
                    $stmt->keyVar->name,
                    PicoType::fromString($keyType)
                );
            }
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            assert($stmt->name instanceof \PhpParser\Node\Identifier);
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
            assert($this->currentClass !== null);
            $methodName = $stmt->name->toString();
            // Abstract methods have no body — already registered in registerClasses
            if ($stmt->stmts === null) {
                return;
            }
            assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType instanceof \PhpParser\Node\Name || $stmt->returnType instanceof \PhpParser\Node\UnionType || $stmt->returnType === null);
            $returnType = $stmt->returnType !== null ? $this->typeFromNode($stmt->returnType) : PicoType::fromString('void');
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
                assert(count($catch->types) > 0);
                $catchTypeName = $catch->types[0]->toString();
                assert(isset($this->classRegistry[$catchTypeName]), "catch class {$catchTypeName} not found");
                if ($catch->var !== null) {
                    assert(is_string($catch->var->name));
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
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true, rType: $rtype);
            $line = $this->getLine($expr);
            $compatible = $ltype->isEqualTo($rtype)
                || $this->isAssignmentCompatible($ltype, $rtype);
            assert($compatible, "line {$line}, type mismatch in assignment: {$ltype->toString()} = {$rtype->toString()}");
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $pData->lVal = $lVal;
            assert(is_string($expr->name));
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
            assert(!is_null($pData->symbol), "line {$line}, symbol not found: {$expr->name}");
            return $pData->symbol->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $pData->lVal = $lVal;
            $type = $this->resolveExpr($expr->var, $doc, lVal: $lVal);
            if ($type->isArray()) {
                if ($expr->dim !== null) {
                    $dimType = $this->resolveExpr($expr->dim);
                    if ($type->hasStringKeys()) {
                        assert($dimType->isEqualTo(PicoType::fromString('string')), "{$dimType->toString()} is not a string for string-keyed array");
                    } else {
                        assert($dimType->isEqualTo(PicoType::fromString('int')), "{$dimType->toString()} is not an int");
                    }
                }
                // dim === null means $arr[] = ... (push), resolved at Assign
                return $type->getElementType();
            }
            // string indexing
            assert($type->isEqualTo(PicoType::fromString('string')), "{$type->toString()} is not a string or array");
            assert($expr->dim !== null);
            $dimType = $this->resolveExpr($expr->dim);
            assert($dimType->isEqualTo(PicoType::fromString('int')), "{$dimType->toString()} is not an int");
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
                assert($ltype->isEqualTo($rtype), "line {$line}, type mismatch in binary op: {$ltype->toString()} {$sigil} {$rtype->toString()}");
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
            assert($type->isEqualTo(PicoType::fromString('int')));
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
            assert($expr->class instanceof \PhpParser\Node\Name);
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $expr->class->toString();
            if (isset($this->enumRegistry[$className])) {
                return PicoType::enum($className);
            }
            // Non-enum class constants not yet supported
            throw new \Exception("class constant {$className}::{$expr->name->toString()} not supported");
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            if ($expr->name->toLowerString() === 'null') {
                return PicoType::fromString('string'); // null represented as ptr
            }
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            assert($expr->name instanceof \PhpParser\Node\Name);
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
            if ($funcName === 'substr' || $funcName === 'trim' || $funcName === 'str_repeat' || $funcName === 'str_replace'
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
                assert(count($expr->args) >= 1);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
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
                assert(count($expr->args) === 1);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrType = $this->resolveExpr($expr->args[0]->value);
                assert($arrType->isArray());
                return $arrType->getElementType();
            }
            if ($funcName === 'array_splice') {
                return PicoType::fromString('void');
            }
            $s = $this->symbolTable->lookup($expr->name->name);
            $line = $this->getLine($expr);
            assert($s !== null, "line {$line}, function {$expr->name->name} not found");
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
            assert($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            if ($className === 'self') {
                assert($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            assert(isset($this->classRegistry[$className]), "class {$className} not found");
            $this->resolveArgs($expr->args);
            return PicoType::object($className);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            $pData->lVal = $lVal;
            $objType = $this->resolveExpr($expr->var);
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            // Enum ->value access
            if ($objType->isEnum() && $expr->name->toString() === 'value') {
                $enumMeta = $this->enumRegistry[$objType->getClassName()];
                if ($enumMeta->backingType === 'string') {
                    return PicoType::fromString('string');
                }
                return PicoType::fromString('int');
            }
            assert($objType->isObject(), "property fetch on non-object type: {$objType->toString()}");
            $classMeta = $this->classRegistry[$objType->getClassName()];
            return $classMeta->getPropertyType($expr->name->toString());
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            $objType = $this->resolveExpr($expr->var);
            assert($objType->isObject(), "method call on non-object type: {$objType->toString()}");
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $objType->getClassName();
            $methodName = $expr->name->toString();
            assert(isset($this->classRegistry[$className]), "class {$className} not found in registry for method call {$methodName}");
            $classMeta = $this->classRegistry[$className];
            assert(isset($classMeta->methods[$methodName]), "method {$methodName} not found on class {$className}");
            $this->resolveArgs($expr->args);
            return $classMeta->methods[$methodName]->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            $pData->lVal = $lVal;
            assert($expr->class instanceof \PhpParser\Node\Name);
            assert($expr->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $className = $expr->class->toString();
            if ($className === 'self') {
                assert($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            assert(isset($classMeta->staticProperties[$propName]), "static property {$propName} not found on {$className}");
            return $classMeta->staticProperties[$propName];
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            assert($expr->class instanceof \PhpParser\Node\Name);
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $expr->class->toString();
            $methodName = $expr->name->toString();
            $this->resolveArgs($expr->args);
            if ($className === 'self') {
                assert($this->currentClass !== null);
                $className = $this->currentClass->name;
            }
            if ($className === 'parent') {
                assert($this->currentClass !== null);
                assert($this->currentClass->parentName !== null, "parent:: used but class has no parent");
                $parentMeta = $this->classRegistry[$this->currentClass->parentName];
                assert(isset($parentMeta->methods[$methodName]), "method {$methodName} not found on parent {$this->currentClass->parentName}");
                return $parentMeta->methods[$methodName]->type;
            }
            // Regular static call — resolve class
            assert(isset($this->classRegistry[$className]), "class {$className} not found");
            $classMeta = $this->classRegistry[$className];
            assert(isset($classMeta->methods[$methodName]), "method {$methodName} not found on {$className}");
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
            assert($resultType !== null);
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
            assert($arg instanceof \PhpParser\Node\Arg);
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
            assert($param->var instanceof \PhpParser\Node\Expr\Variable);
            assert(is_string($param->var->name));
            assert($param->type instanceof \PhpParser\Node\Identifier || $param->type instanceof \PhpParser\Node\NullableType || $param->type instanceof \PhpParser\Node\Name || $param->type instanceof \PhpParser\Node\UnionType);
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
            assert($this->resolveExpr($prop->default) === $type);
        }
        $pData->symbol = $this->symbolTable->addSymbol($prop->name, $type);
    }

    protected function getLine(\PhpParser\Node $node): int
    {
        $line = 0;
        if ($node->hasAttribute("startLine")) {
            $line = $node->getAttribute("startLine");
            assert(is_int($line));
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

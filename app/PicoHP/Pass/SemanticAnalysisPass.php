<?php

namespace App\PicoHP\Pass;

use App\PicoHP\{PassInterface, SymbolTable};
use App\PicoHP\SymbolTable\{ClassMetadata, DocTypeParser, PicoHPData};
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

    public function exec(): void
    {
        $this->registerClasses($this->ast);
        $this->registerFunctions($this->ast);
        $this->resolveStmts($this->ast);
    }

    /**
     * Pre-pass: register class metadata (properties and method signatures).
     *
     * @param array<\PhpParser\Node> $stmts
     */
    protected function registerClasses(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                $this->registerClasses($stmt->stmts);
                continue;
            }
            if ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
                assert($stmt->name instanceof \PhpParser\Node\Identifier);
                $className = $stmt->name->toString();
                $classMeta = new ClassMetadata($className);
                $this->classRegistry[$className] = $classMeta;
                foreach ($stmt->stmts as $classStmt) {
                    if ($classStmt instanceof \PhpParser\Node\Stmt\Property) {
                        assert($classStmt->type instanceof \PhpParser\Node\Identifier || $classStmt->type instanceof \PhpParser\Node\NullableType);
                        // Use PHPDoc annotation if available (for generic types like array<int, string>)
                        $doc = $classStmt->getDocComment();
                        if ($doc !== null) {
                            $propType = $this->docTypeParser->parseType($doc->getText());
                        } else {
                            $propType = $this->typeFromNode($classStmt->type);
                        }
                        foreach ($classStmt->props as $prop) {
                            $classMeta->addProperty($prop->name->toString(), $propType);
                        }
                    } elseif ($classStmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                        $methodName = $classStmt->name->toString();
                        assert($classStmt->returnType === null || $classStmt->returnType instanceof \PhpParser\Node\Identifier || $classStmt->returnType instanceof \PhpParser\Node\NullableType);
                        $returnType = $classStmt->returnType !== null
                            ? $this->typeFromNode($classStmt->returnType)
                            : PicoType::fromString('void');
                        $methodSymbol = new \App\PicoHP\SymbolTable\Symbol($methodName, $returnType, func: true);
                        $classMeta->methods[$methodName] = $methodSymbol;
                    }
                }
            }
        }
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
                assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType);
                $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
                if ($existing === null) {
                    $this->symbolTable->addSymbol(
                        $stmt->name->name,
                        $this->typeFromNode($stmt->returnType),
                        func: true
                    );
                }
            }
        }
    }

    private function typeFromNode(\PhpParser\Node\Identifier|\PhpParser\Node\NullableType $node): PicoType
    {
        if ($node instanceof \PhpParser\Node\NullableType) {
            assert($node->type instanceof \PhpParser\Node\Identifier);
            return PicoType::fromString('?' . $node->type->name);
        }
        return PicoType::fromString($node->name);
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
            assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType);
            $returnType = $this->typeFromNode($stmt->returnType);
            $existing = $this->symbolTable->lookupCurrentScope($stmt->name->name);
            $pData->symbol = $existing ?? $this->symbolTable->addSymbol($stmt->name->name, $returnType, func: true);
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->symbolTable->enterScope());
            }

            $pData->getSymbol()->params = $this->resolveParams($stmt->params);
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
                if ($this->currentFunctionReturnType !== null && !$exprType->isEqualTo($this->currentFunctionReturnType)) {
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
            // TODO: key var support
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
            assert($stmt->returnType instanceof \PhpParser\Node\Identifier || $stmt->returnType instanceof \PhpParser\Node\NullableType || $stmt->returnType === null);
            $returnType = $stmt->returnType !== null ? $this->typeFromNode($stmt->returnType) : PicoType::fromString('void');
            $methodSymbol = $this->symbolTable->addSymbol($methodName, $returnType, func: true);
            $pData->symbol = $methodSymbol;
            $this->currentClass->methods[$methodName] = $methodSymbol;
            $pData->setScope($this->symbolTable->enterScope());
            // Add $this to method scope
            $this->symbolTable->addSymbol('this', PicoType::object($this->currentClass->name));
            $methodSymbol->params = $this->resolveParams($stmt->params);
            $previousReturnType = $this->currentFunctionReturnType;
            $this->currentFunctionReturnType = $returnType;
            assert($stmt->stmts !== null);
            $this->resolveStmts($stmt->stmts);
            $this->currentFunctionReturnType = $previousReturnType;
            $this->symbolTable->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
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
            assert($ltype->isEqualTo($rtype), "line {$line}, type mismatch in assignment");
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
                    assert($dimType->isEqualTo(PicoType::fromString('int')), "{$dimType->toString()} is not an int");
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
            assert($ltype->isEqualTo($rtype), "line {$line}, type mismatch in binary op: {$ltype->toString()} {$expr->getOperatorSigil()} {$rtype->toString()}");
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            if ($expr->name->toLowerString() === 'null') {
                return PicoType::fromString('string'); // null represented as ptr
            }
            return PicoType::fromString('bool');
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            $this->resolveArgs($expr->args);
            assert($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            // Built-in functions
            if ($funcName === 'count' || $funcName === 'strlen') {
                return PicoType::fromString('int');
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
            $first = true;
            foreach ($expr->items as $item) {
                $itemType = $this->resolveExpr($item->value);
                if ($first) {
                    $elemType = $itemType;
                    $first = false;
                }
            }
            return PicoType::array($elemType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\New_) {
            assert($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            assert(isset($this->classRegistry[$className]), "class {$className} not found");
            $this->resolveArgs($expr->args);
            return PicoType::object($className);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            $pData->lVal = $lVal;
            $objType = $this->resolveExpr($expr->var);
            assert($objType->isObject(), "property fetch on non-object type: {$objType->toString()}");
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $classMeta = $this->classRegistry[$objType->getClassName()];
            return $classMeta->getPropertyType($expr->name->toString());
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            $objType = $this->resolveExpr($expr->var);
            assert($objType->isObject(), "method call on non-object type: {$objType->toString()}");
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $objType->getClassName();
            $methodName = $expr->name->toString();
            $classMeta = $this->classRegistry[$className];
            assert(isset($classMeta->methods[$methodName]), "method {$methodName} not found on class {$className}");
            $this->resolveArgs($expr->args);
            return $classMeta->methods[$methodName]->type;
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
     * @return array<PicoType>
     */
    public function resolveParams(array $params): array
    {
        $paramTypes = [];
        foreach ($params as $param) {
            $pData = $this->getPicoData($param);
            assert($param->var instanceof \PhpParser\Node\Expr\Variable);
            assert(is_string($param->var->name));
            assert($param->type instanceof \PhpParser\Node\Identifier || $param->type instanceof \PhpParser\Node\NullableType);
            $paramType = $this->typeFromNode($param->type);
            $pData->symbol = $this->symbolTable->addSymbol($param->var->name, $paramType);
            $paramTypes[] = $paramType;
        }
        return $paramTypes;
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

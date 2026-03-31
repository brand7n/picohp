<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class ClassToFunctionVisitor extends NodeVisitorAbstract
{
    /** FQCN of the innermost class/enum being visited. */
    protected ?string $classFqcn = null;

    /** @var list<string|null> */
    protected array $namespaceStack = [];

    /** @var Node\Stmt[] */
    protected array $globalStatements = [];

    protected bool $insideTrait = false;

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

    /** @return Node\Name Fully-qualified name for a known FQCN. */
    protected function nameFromFqcn(string $fqcn): Node\Name
    {
        return new FullyQualified($fqcn);
    }

    /** @return null|int|Node|Node[] */
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $childNs = $node->name !== null ? $node->name->toString() : '';
            $merged = $childNs === '' ? null : $childNs;
            $this->pushNamespace($merged);
        }
        // Skip trait nodes — traits are inlined into classes during semantic analysis
        if ($node instanceof Node\Stmt\Trait_) {
            $this->insideTrait = true;
        }
        // Capture the class name for use in transformations
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            \App\PicoHP\CompilerInvariant::check($node->name !== null);
            $this->classFqcn = ClassSymbol::fqcn($this->currentNamespace(), $node->name->name);
        }

        return null;
    }

    /** @return null|int|Node|Node[] */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->popNamespace();
        }
        if ($node instanceof Node\Stmt\Trait_) {
            $this->insideTrait = false;

            return null;
        }
        if ($this->insideTrait) {
            return null;
        }

        // Transform methods into functions
        if ($node instanceof Node\Stmt\ClassMethod && $node->isStatic()) {
            $methodName = $node->name->name;

            // Transform static methods: no `$state` parameter
            $stmts = $node->stmts;
            if ($stmts === null) {
                // assume this is a interface method for now
                return null;
            }
            \App\PicoHP\CompilerInvariant::check($this->classFqcn !== null);
            $funcName = ClassSymbol::llvmMethodSymbol($this->classFqcn, $methodName);
            $docAttributes = [];
            if ($node->hasAttribute('comments')) {
                $docAttributes['comments'] = $node->getAttribute('comments');
            }
            $returnType = $node->returnType;
            if ($returnType instanceof Node\Identifier && $returnType->name === 'self') {
                $returnType = $this->nameFromFqcn($this->classFqcn);
            } elseif ($returnType instanceof Node\Name && $returnType->toString() === 'self') {
                $returnType = $this->nameFromFqcn($this->classFqcn);
            } elseif ($returnType instanceof Node\NullableType && $returnType->type instanceof Node\Identifier && $returnType->type->name === 'self') {
                $returnType = new Node\NullableType($this->nameFromFqcn($this->classFqcn));
            } elseif ($returnType instanceof Node\NullableType && $returnType->type instanceof Node\Name && $returnType->type->toString() === 'self') {
                $returnType = new Node\NullableType($this->nameFromFqcn($this->classFqcn));
            }
            $this->globalStatements[] = new Node\Stmt\Function_(
                $funcName,
                [
                    'params' => $node->params,
                    'stmts' => $stmts,
                    'returnType' => $returnType,
                    'attrGroups' => $node->attrGroups,
                ],
                $docAttributes
            );

            return NodeTraverser::REMOVE_NODE;
        }

        // Resolve self:: in static property access to the actual class name
        if ($node instanceof Node\Expr\StaticPropertyFetch) {
            if ($node->class instanceof Node\Name && ($node->class->toString() === 'self' || $node->class->toString() === 'static')) {
                \App\PicoHP\CompilerInvariant::check($this->classFqcn !== null);
                $node->class = $this->nameFromFqcn($this->classFqcn);
            }

            return $node;
        }

        // Resolve self:: in class constant fetch (e.g., self::CASE_NAME)
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->class->toString() === 'self') {
            \App\PicoHP\CompilerInvariant::check($this->classFqcn !== null);
            $node->class = $this->nameFromFqcn($this->classFqcn);

            return $node;
        }

        // Resolve self in new expressions (e.g., new self())
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $node->class->toString() === 'self') {
            \App\PicoHP\CompilerInvariant::check($this->classFqcn !== null);
            $node->class = $this->nameFromFqcn($this->classFqcn);

            return $node;
        }

        // Convert static method calls (e.g., MyClass::methodName())
        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $rawClass = $node->class->toString();
                if ($rawClass === 'self') {
                    \App\PicoHP\CompilerInvariant::check($this->classFqcn !== null);
                    $classFqcn = $this->classFqcn;
                    $node->class = $this->nameFromFqcn($classFqcn);
                } elseif ($rawClass === 'parent') {
                    return null; // leave as StaticCall
                } else {
                    // Short names from `use` / `use X\{A, B}` stay as one segment; NameResolver sets resolvedName.
                    $classFqcn = ClassSymbol::fqcnFromResolvedName($node->class, $this->currentNamespace());
                }
                \App\PicoHP\CompilerInvariant::check($node->name instanceof Node\Identifier);
                $symbol = ClassSymbol::llvmMethodSymbol($classFqcn, $node->name->toString());

                return new Node\Expr\FuncCall(new Node\Name($symbol), $node->args);
            }

            // Dynamic static call ($expr::method()) — keep as StaticCall; semantic pass rejects or lowers later.
            return null;
        }

        return null;
    }

    /**
     * Called after the AST has been traversed.
     *
     * @param Node[] $nodes
     *
     * @return Node[]
     */
    public function afterTraverse(array $nodes): array
    {
        // Handle `declare` statements (keep them at the very top)
        $declareNodes = array_filter($nodes, fn ($node) => $node instanceof Node\Stmt\Declare_);
        $otherNodes = array_filter($nodes, fn ($node) => !$node instanceof Node\Stmt\Declare_);

        // Prepend the `main` function after `declare` statements
        return array_merge(
            $declareNodes,
            $this->globalStatements,
            $otherNodes,
        );
    }
}

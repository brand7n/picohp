<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;

class ClassToFunctionVisitor extends NodeVisitorAbstract
{
    protected ?string $className = null;

    /** @var Node\Stmt[] */
    protected array $globalStatements = [];

    protected bool $insideTrait = false;

    /** @return null|int|Node|Node[] */
    public function enterNode(Node $node)
    {
        // Skip trait nodes — traits are inlined into classes during semantic analysis
        if ($node instanceof Node\Stmt\Trait_) {
            $this->insideTrait = true;
        }
        // Capture the class name for use in transformations
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            assert($node->name !== null);
            $this->className = $node->name->name;
        }
        return null;
    }

    /** @return null|int|Node|Node[] */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Trait_) {
            $this->insideTrait = false;
            return null;
        }
        if ($this->insideTrait) {
            return null;
        }

        // TODO: theoretically, we could remove the namespace declaration
        // but we need to handle the statements inside the namespace
        // if ($node instanceof Node\Stmt\Namespace_) {
        //     return NodeTraverser::REMOVE_NODE;
        // }

        // Transform methods into functions
        if ($node instanceof Node\Stmt\ClassMethod && $node->isStatic()) {
            $methodName = $node->name->name;

            // Transform static methods: no `$state` parameter
            $stmts = $node->stmts;
            if ($stmts === null) {
                // assume this is a interface method for now
                return null;
            }
            $this->globalStatements[] = new Node\Stmt\Function_(
                "{$this->className}_{$methodName}",
                [
                    'params' => $node->params,
                    'stmts' => $stmts,
                    'returnType' => $node->returnType,
                ]
            );
            return NodeTraverser::REMOVE_NODE;
        }

        // Resolve self:: in static property access to the actual class name
        if ($node instanceof Node\Expr\StaticPropertyFetch) {
            if ($node->class instanceof Node\Name && ($node->class->toString() === 'self' || $node->class->toString() === 'static')) {
                assert($this->className !== null);
                $node->class = new Node\Name($this->className);
            }
            return $node;
        }

        // Resolve self:: in class constant fetch (e.g., self::CASE_NAME)
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->class->toString() === 'self') {
            assert($this->className !== null);
            $node->class = new Node\Name($this->className);
            return $node;
        }

        // Resolve self in new expressions (e.g., new self())
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && $node->class->toString() === 'self') {
            assert($this->className !== null);
            $node->class = new Node\Name($this->className);
            return $node;
        }

        // Convert static method calls (e.g., MyClass::methodName())
        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $className = $node->class->toString();
                if ($className === 'self') {
                    assert($this->className !== null);
                    $className = $this->className;
                    $node->class = new Node\Name($className);
                }
                if ($className === 'parent') {
                    return null; // leave as StaticCall
                }
                assert($node->name instanceof Node\Identifier);
                $name = new Node\Name("{$node->class->name}_{$node->name}");
                return new Node\Expr\FuncCall($name, $node->args);
            }
            // @codeCoverageIgnoreStart
            dump($node);
            throw new \Exception('Unexpected node type');
            // @codeCoverageIgnoreEnd
        }

        return null;
    }

    /**
     * Called after the AST has been traversed.
     *
     * @param Node[] $nodes
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

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

    /** @return null|int|Node|Node[] */
    public function enterNode(Node $node)
    {
        // Capture the class name for use in transformations
        if ($node instanceof Node\Stmt\Class_) {
            assert($node->name !== null);
            $this->className = $node->name->name;
        }
        return null;
    }

    /** @return null|int|Node|Node[] */
    public function leaveNode(Node $node)
    {
        // Transform methods into functions
        if ($node instanceof Node\Stmt\ClassMethod && $node->isStatic()) {
            $methodName = $node->name->name;

            // Transform static methods: no `$state` parameter
            $stmts = $node->stmts;
            assert($stmts !== null);
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

        // Convert static method calls (e.g., MyClass::methodName())
        if ($node instanceof Node\Expr\StaticCall) {
            // TODO: handle self::
            if ($node->class instanceof Node\Name && $node->class->toString() === $this->className) {
                assert($node->name instanceof Node\Identifier);
                $name = new Node\Name("{$this->className}_{$node->name}");
                // TODO: handle arguments/return value
                return new Node\Expr\FuncCall($name);
            }
            // @codeCoverageIgnoreStart
            assert($node->class instanceof Node\Name);
            throw new \Exception("Unexpected class name: {$node->class->toString()}");
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

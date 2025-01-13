<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;

class GlobalToMainVisitor extends NodeVisitorAbstract
{
    protected ?string $className = null;

    /** @var Node\Stmt[] */
    protected array $globalStatements = [];

    protected int $currentDepth = 0;

    /** @return null|int|Node|Node[] */
    public function enterNode(Node $node)
    {
        $this->currentDepth++;

        $node->setAttribute("depth", $this->currentDepth);
        return null;
    }

    /** @return null|int|Node|Node[] */
    public function leaveNode(Node $node)
    {
        $ret = null;
        if ($this->currentDepth === 1 && !$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\Class_) {
            assert($node instanceof Node\Stmt);
            $this->globalStatements[] = $node;

            // Remove the statement from the top level
            $ret = NodeTraverser::REMOVE_NODE;
        }

        $this->currentDepth--;

        return $ret;
    }

    /**
     * Called after the AST has been traversed.
     *
     * @param Node[] $nodes
     * @return Node[]
     */
    public function afterTraverse(array $nodes): array
    {
        // Add return 0 at the end of the main function
        $this->globalStatements[] = new Node\Stmt\Return_(
            new Node\Scalar\LNumber(0)
        );

        // Create a `main` function with the collected statements
        $mainFunction = new Node\Stmt\Function_(
            'main',
            [
                'stmts' => $this->globalStatements,
                'returnType' => new Node\Identifier('int'), // Define return type as int
            ]
        );

        // Handle `declare` statements (keep them at the very top)
        $declareNodes = array_filter($nodes, fn ($node) => $node instanceof Node\Stmt\Declare_);
        $otherNodes = array_filter($nodes, fn ($node) => !$node instanceof Node\Stmt\Declare_);

        // Prepend the `main` function after `declare` statements
        return array_merge(
            $declareNodes,
            [$mainFunction],
            $otherNodes,
        );
    }
}

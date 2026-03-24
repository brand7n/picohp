<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;

class GlobalToMainVisitor extends NodeVisitorAbstract
{
    /** @var Node\Stmt[] */
    protected array $globalStatements = [];

    protected int $currentDepth = 0;

    public bool $hasMain = false;

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
        if ($this->currentDepth === 1
            && !$node instanceof Node\Stmt\Function_
            && !$node instanceof Node\Stmt\Class_
            && !$node instanceof Node\Stmt\Interface_
            && !$node instanceof Node\Stmt\Trait_
        ) {
            \App\PicoHP\CompilerInvariant::check($node instanceof Node\Stmt);
            if ($node instanceof Node\Stmt\Namespace_) {
                // Split namespace: declarations stay top-level, executable code goes to main
                $keepInNamespace = [];
                foreach ($node->stmts as $nsStmt) {
                    if ($nsStmt instanceof Node\Stmt\Class_
                        || $nsStmt instanceof Node\Stmt\Function_
                        || $nsStmt instanceof Node\Stmt\Interface_
                        || $nsStmt instanceof Node\Stmt\Use_
                    ) {
                        $keepInNamespace[] = $nsStmt;
                    } else {
                        $this->globalStatements[] = $nsStmt;
                    }
                }
                if (count($keepInNamespace) > 0) {
                    $node->stmts = $keepInNamespace;
                    // Keep the namespace at top level with only declarations
                } else {
                    $ret = NodeTraverser::REMOVE_NODE;
                }
            } else {
                $this->globalStatements[] = $node;
                $ret = NodeTraverser::REMOVE_NODE;
            }
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
        // Filter out declare statements — they stay at the top regardless
        $declareNodes = array_filter($nodes, fn ($node) => $node instanceof Node\Stmt\Declare_);
        $otherNodes = array_values(array_filter($nodes, fn ($node) => !$node instanceof Node\Stmt\Declare_));

        // If no executable statements were collected, this is a library (no main needed)
        if (count($this->globalStatements) === 0) {
            $this->hasMain = false;
            return array_merge($declareNodes, $otherNodes);
        }
        $this->hasMain = true;

        // Add return 0 at the end of the main function
        $this->globalStatements[] = new Node\Stmt\Return_(
            new Node\Scalar\LNumber(0)
        );

        // Create a `main` function with the collected statements
        $mainFunction = new Node\Stmt\Function_(
            'main',
            [
                'stmts' => $this->globalStatements,
                'returnType' => new Node\Identifier('int'),
            ]
        );

        return array_merge(
            $declareNodes,
            [$mainFunction],
            $otherNodes,
        );
    }
}

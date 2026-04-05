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
                        || $nsStmt instanceof Node\Stmt\Trait_
                        || $nsStmt instanceof Node\Stmt\Enum_
                        || $nsStmt instanceof Node\Stmt\Use_
                        || $nsStmt instanceof Node\Stmt\GroupUse
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

        // Create a `main` function with argc/argv parameters
        /** @param list<string> $argv */
        $mainFunction = new Node\Stmt\Function_(
            'main',
            [
                'params' => [
                    new Node\Param(
                        new Node\Expr\Variable('argc'),
                        type: new Node\Identifier('int'),
                    ),
                    new Node\Param(
                        new Node\Expr\Variable('argv'),
                        type: new Node\Identifier('array'),
                    ),
                ],
                'stmts' => $this->globalStatements,
                'returnType' => new Node\Identifier('int'),
            ]
        );
        $mainFunction->setDocComment(new \PhpParser\Comment\Doc('/** @param list<string> $argv */'));

        return array_merge(
            $declareNodes,
            [$mainFunction],
            $otherNodes,
        );
    }
}

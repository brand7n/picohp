<?php

declare(strict_types=1);

namespace App\Parser;

use App\LLVM\EchoEmitter;
use App\LLVM\FunctionEmitter;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class AstNodeVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node)
    {
        $emitter = null;
        switch ($node->getType()) {
            case 'Stmt_Echo':
                $emitter = new EchoEmitter();
                break;

            case 'Stmt_Function':
                $funcNode = new Function_($node);
                $emitter = new FunctionEmitter($funcNode->getName(), $funcNode->getParams());
                break;
        }

        if ($emitter instanceof \App\LLVM\BaseEmitter) {
            $node->setAttribute('emitter', $emitter);
            $emitter->begin();
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        $type = $type = $node->getType();

        if ($node->hasAttribute('emitter')) {
            $emitter = $node->getAttribute('emitter');
            if ($emitter instanceof \App\LLVM\BaseEmitter) {
                $emitter->end();
            }
        }

        return null;
    }
}

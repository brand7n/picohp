<?php

namespace App\Parser;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use Illuminate\Support\Str;
use App\LLVM\FunctionEmitter;
use App\LLVM\EchoEmitter;

final class AstNodeVisitor extends NodeVisitorAbstract {
    public function enterNode(Node $node) {
        // if (!$this->checkNode($node)) {
        //     return;
        // }
        $parentNode = $node->getAttribute('parent');
        $parent = '';
        if ($parentNode instanceof Node) {
            $parent = $parentNode->getType() ;
        }
        $name = isset($node->name) ? $node->name : '';
        echo 'enter: ' . $node->getType() . ' p: ' . $parent . ' n: ' . $name .PHP_EOL;

        if ($node->getType() === 'Stmt_Echo') {
            $emitter = new EchoEmitter();
            $node->setAttribute('emitter', $emitter);
            $emitter->begin();
        }
        if ($node->getType() === 'Scalar_Int') {
        }
        return null;
    }

    public function leaveNode(Node $node) {
        // if (!$this->checkNode($node)) {
        //     return;
        // }
        echo 'leave: ' . $node->getType() . PHP_EOL;
        if ($node->hasAttribute('emitter')) {
            $emitter = $node->getAttribute('emitter');
            if ($emitter instanceof \App\LLVM\BaseEmitter) {
                $emitter->end();
            }
        }
        return null;
    }

    // private function checkNode(Node $node) : bool {
    //     return (
    //         Str::startsWith($node->getType(), 'Stmt_') ||
    //         Str::startsWith($node->getType(), 'Expr_')
    //     );
    // }
}

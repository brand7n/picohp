<?php declare(strict_types = 1);

namespace App\Parser;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use Illuminate\Support\Str;
use App\LLVM\FunctionEmitter;
use App\LLVM\EchoEmitter;

final class AstNodeVisitor extends NodeVisitorAbstract {
    public function enterNode(Node $node) {
        $parentNode = $node->getAttribute('parent');

        $type = get_class($node);
        echo "enter: $type" . PHP_EOL;
        if ($parentNode instanceof Node) {
            $parent = get_class($parentNode);
            echo "    parent: $parent" . PHP_EOL;
        }
        if ($node instanceof \PhpParser\Node\Identifier ||
            $node instanceof \PhpParser\Node\Name ||
            $node instanceof \PhpParser\Node\Expr\Variable) {
            $name = is_string($node->name) ? $node->name : '';
            echo "    name: $name" . PHP_EOL;
        }

        $emitter = null;
        if ($node->getType() === 'Stmt_Echo') {
            $emitter = new EchoEmitter();
        }

        if ($emitter instanceof \App\LLVM\BaseEmitter) {
            $node->setAttribute('emitter', $emitter);
            $emitter->begin();
        }

        return null;
    }

    public function leaveNode(Node $node) {
        $type = get_class($node);
        echo "leave: $type" . PHP_EOL;
        if ($node->hasAttribute('emitter')) {
            $emitter = $node->getAttribute('emitter');
            if ($emitter instanceof \App\LLVM\BaseEmitter) {
                $emitter->end();
            }
        }
        return null;
    }
}

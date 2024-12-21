<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Illuminate\Support\Str;
use App\LLVM\FunctionEmitter;
use App\LLVM\EchoEmitter;

/*
TODO:
use stack.ll to implement expression parsing
*/

final class MyVisitor extends NodeVisitorAbstract {
    public function enterNode(Node $node) {
        // if (!$this->checkNode($node)) {
        //     return;
        // }
        $parentNode = $node->getAttribute('parent');
        $parent = isset($parentNode) ? $parentNode->getType() : '';
        $name = isset($node->name) ? $node->name : '';
        echo 'enter: ' . $node->getType() . ' p: ' . $parent . ' n: ' . $name .PHP_EOL;

        if ($node->getType() === 'Stmt_Echo') {
            $emitter = new EchoEmitter();
            $node->setAttribute('emitter', $emitter);
            $emitter->begin();
        }
        if ($node->getType() === 'Scalar_Int') {
            
        }
    }

    public function leaveNode(Node $node) {
        // if (!$this->checkNode($node)) {
        //     return;
        // }
        echo 'leave: ' . $node->getType() . PHP_EOL;
        if ($node->hasAttribute('emitter')) {
            $emitter = $node->getAttribute('emitter');
            $emitter->end();
        }
    }

    private function checkNode(Node $node) : bool {
        return true;
        return (
            Str::startsWith($node->getType(), 'Stmt_') ||
            Str::startsWith($node->getType(), 'Expr_')
        );
    }
}

test('parse', function () {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor(new ParentConnectingVisitor);

    $code = <<<'CODE'
    <?php

    //function test(int $a) : int
    //{
    //    poke(1234, $a);
    //    return peek(1234);
    //}

    //echo(test($a));
    echo(1);
    CODE;

    $stmts = $parser->parse($code);
    $stmts = $traverser->traverse($stmts);

    $traverser2 = new NodeTraverser();
    $traverser2->addVisitor(new MyVisitor);

    $main = new FunctionEmitter("main", []);
    $main->begin();
    $traverser2->traverse($stmts);
    $main->end();

    expect(true)->toBeTrue();
});

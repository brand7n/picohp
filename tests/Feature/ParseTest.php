<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use App\LLVM\FunctionEmitter;

/*
TODO:
- use stack.ll to implement expression parsing?
- integrate phpstan
*/

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
    $traverser2->addVisitor(new \App\Parser\AstNodeVisitor);

    $main = new FunctionEmitter("main", []);
    $main->begin();
    $traverser2->traverse($stmts);
    $main->end();

    expect(true)->toBeTrue();
});

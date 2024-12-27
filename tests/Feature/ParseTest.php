<?php

declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use App\LLVM\FunctionEmitter;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

/*
TODO:
- use stack.ll to implement expression parsing?
- different runtimes in rust, 8-bit micro, hosted OS, etc
- integrate phpstan, extension?
*/

test('parse', function () {
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor(new ParentConnectingVisitor);

    $code = <<<'CODE'
    <?php

    function main(int $args): int {
        $a = 5 * 4 + 3;
        echo(1);
        return $a;
    }

    function test1(): void {}
    CODE;

    $stmts = $parser->parse($code);
    if (is_null($stmts)) {
        return;
    }
    $stmts = $traverser->traverse($stmts);

    $traverser2 = new NodeTraverser;
    $traverser2->addVisitor(new \App\Parser\AstNodeVisitor);

    //$main = new FunctionEmitter("main", []);
    //$main->begin();
    $traverser2->traverse($stmts);
    //$main->end();

    $result = -1;
    exec('clang out.ll -o test', result_code: $result);
    expect($result)->toBe(0);
});

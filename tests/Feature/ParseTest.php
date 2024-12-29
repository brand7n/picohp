<?php

declare(strict_types=1);

use PhpParser\ParserFactory;

/*
TODO:
- use stack.ll to implement expression parsing?
- different runtimes in rust, 8-bit micro, hosted OS, etc
- integrate phpstan, extension for narrowing language?
- can we use type info from phpstan or some other lib?
- create a obj we can add to the AST as an attribute which contains:
  - emitter reference?
  - symbol table entry?
  - abstraction layer between AST Node from parser and LLVM/generic emitter layer
*/

it('parses a PHP program', function () {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    $code = <<<'CODE'
    <?php

    function main(int $args): int {
        $a = 5 + 4 * 3;
        return $a;
    }

    function test1(): void {}
    CODE;

    $stmts = $parser->parse($code);

    if (is_null($stmts)) {
        throw new \Exception("stmts is null");
    }
    $names = [];
    foreach ($stmts as $stmt) {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $names[] = $stmt->name->toString();
        }
    }

    expect(count($names))->toBe(2);
    expect($names[0])->toBe('main');
    expect($names[1])->toBe('test1');
});
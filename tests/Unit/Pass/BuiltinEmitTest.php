<?php

declare(strict_types=1);

use App\PicoHP\Pass\IRGenerationPass;
use App\PicoHP\Pass\SemanticAnalysisPass;

it('emits builtin exception class methods from registry', function () {
    $code = <<<'PHP'
<?php
declare(strict_types=1);
try {
    throw new RuntimeException('test error');
} catch (RuntimeException $e) {
    echo $e->getMessage() . "\n";
}
echo "done\n";
PHP;

    $parser = new \PhpParser\Parser\Php8(new \PhpParser\Lexer());
    $ast = $parser->parse($code);
    assert($ast !== null);

    $traverser = new \PhpParser\NodeTraverser();
    $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
    $ast = $traverser->traverse($ast);

    $traverser = new \PhpParser\NodeTraverser();
    $traverser->addVisitor(new \App\PicoHP\ClassToFunctionVisitor());
    $ast = $traverser->traverse($ast);

    $traverser = new \PhpParser\NodeTraverser();
    $globalToMain = new \App\PicoHP\GlobalToMainVisitor();
    $traverser->addVisitor($globalToMain);
    $ast = $traverser->traverse($ast);

    $sem = new SemanticAnalysisPass($ast);
    $sem->exec();

    $pass = new IRGenerationPass(
        $ast,
        $sem->getClassRegistry(),
        $sem->getEnumRegistry(),
        $sem->getTypeIdMap(),
    );
    $pass->exec();

    $lines = $pass->module->getLines();
    $ir = implode("\n", array_map(fn ($l) => $l->toString(), $lines));

    // RuntimeException struct should be emitted
    expect($ir)->toContain('%struct.RuntimeException');
    // Exception constructor should be emitted
    expect($ir)->toContain('define dso_local void @Exception___construct');
    // getMessage should be emitted
    expect($ir)->toContain('define dso_local ptr @Exception_getMessage');
});

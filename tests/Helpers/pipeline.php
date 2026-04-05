<?php

declare(strict_types=1);

use App\PicoHP\ClassToFunctionVisitor;
use App\PicoHP\GlobalToMainVisitor;
use App\PicoHP\HandLexer\HandLexerAdapter;
use App\PicoHP\Pass\IRGenerationPass;
use App\PicoHP\Pass\SemanticAnalysisPass;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser\Php8;
use PhpParser\PhpVersion;

if (!function_exists('picohpParsePipelineAst')) {
    /**
     * @return array<\PhpParser\Node>
     */
    function picohpParsePipelineAst(string $phpSource): array
    {
        $parser = new Php8(new HandLexerAdapter(), PhpVersion::getNewestSupported());
        $ast = $parser->parse($phpSource);
        if ($ast === null) {
            throw new \RuntimeException('PHP parse returned null');
        }
        $t = new NodeTraverser();
        $t->addVisitor(new NameResolver(options: ['replaceNodes' => false]));
        $ast = $t->traverse($ast);
        $t = new NodeTraverser();
        $t->addVisitor(new ClassToFunctionVisitor());
        $ast = $t->traverse($ast);
        $t = new NodeTraverser();
        $t->addVisitor(new GlobalToMainVisitor());

        return $t->traverse($ast);
    }

    /**
     * ClassToFunction → GlobalToMain → Semantic only (no IR). Use when IR cannot handle the snippet
     * but semantic analysis should still run (e.g. anonymous classes).
     *
     * @param \Closure(string): void|null $semanticWarning Pass explicitly as null to omit the callback
     */
    function picohpRunSemanticOnly(string $phpSource, ?\Closure $semanticWarning = null): void
    {
        $ast = picohpParsePipelineAst($phpSource);
        $callback = func_num_args() === 2 ? $semanticWarning : static fn () => null;
        $sem = new SemanticAnalysisPass($ast, $callback);
        $sem->exec();
    }

    /**
     * ClassToFunction → GlobalToMain → Semantic → IR (no clang). Used for coverage of compiler passes.
     *
     * @param \Closure(string): void|null $semanticWarning Pass explicitly as null to omit the callback
     */
    function picohpRunMiniPipeline(string $phpSource, ?\Closure $semanticWarning = null): void
    {
        $ast = picohpParsePipelineAst($phpSource);
        $callback = func_num_args() === 2 ? $semanticWarning : static fn () => null;
        $sem = new SemanticAnalysisPass($ast, $callback);
        $sem->exec();
        $ir = new IRGenerationPass($ast, $sem->getClassRegistry(), $sem->getEnumRegistry(), $sem->getTypeIdMap());
        $ir->exec();
    }
}

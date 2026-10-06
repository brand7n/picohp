<?php

declare(strict_types=1);

use PicoIR\BaseType;
use PicoIR\Frontend\{AstLoader, AstNode, LoweringPass, TypeMapper};

it('maps phpstan type strings to base types', function (string $phpType, BaseType $expected) {
    expect(TypeMapper::map($phpType))->toBe($expected);
})->with([
    ['int', BaseType::INT],
    ['positive-int', BaseType::INT],
    ['string', BaseType::STRING],
    ['non-empty-string', BaseType::STRING],
    ['bool', BaseType::BOOL],
    ['float', BaseType::FLOAT],
    ['int|null', BaseType::INT],
    ['void', BaseType::VOID],
    ['array', BaseType::PTR],
]);

it('loads a bare-list JSON AST into a root node', function () {
    $node = AstLoader::load(dirname(__DIR__) . '/Fixtures/functions.json');
    expect($node->type())->toBe('_Root');
    expect($node->nodes('stmts'))->not->toBeEmpty();
});

it('reads resolvedType attributes', function () {
    $node = new AstNode(['nodeType' => 'Expr_Variable', 'attributes' => ['resolvedType' => 'int'], 'name' => 'x']);
    expect($node->resolvedType())->toBe('int');
});

it('lowers an int literal echo to a printf call', function () {
    $ast = new AstNode(['nodeType' => '_Root', 'stmts' => [
        ['nodeType' => 'Stmt_Echo', 'attributes' => [], 'exprs' => [
            ['nodeType' => 'Scalar_Int', 'attributes' => [], 'value' => 7],
        ]],
    ]]);
    $ir = (new LoweringPass())->lower($ast)->dump();
    expect($ir)->toContain('call i32 (ptr, ...) @printf');
});

it('rejects an unsupported statement with a clear error', function () {
    $ast = new AstNode(['nodeType' => '_Root', 'stmts' => [
        ['nodeType' => 'Stmt_Class', 'attributes' => ['startLine' => 1]],
    ]]);
    (new LoweringPass())->lower($ast);
})->throws(RuntimeException::class, 'unsupported statement: Stmt_Class');

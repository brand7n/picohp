<?php

declare(strict_types=1);

use App\PicoHP\ClassSymbol;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

it('builds fqcn with or without namespace prefix', function () {
    expect(ClassSymbol::fqcn(null, 'C'))->toBe('C');
    expect(ClassSymbol::fqcn('', 'C'))->toBe('C');
    expect(ClassSymbol::fqcn('A\\B', 'C'))->toBe('A\\B\\C');
});

it('resolves from resolvedName when present', function () {
    $n = new Name('Foo');
    $n->setAttribute('resolvedName', new Name('Resolved'));
    expect(ClassSymbol::fqcnFromResolvedName($n))->toBe('Resolved');
});

it('resolves from namespacedName when present', function () {
    $n = new Name('Foo');
    $n->setAttribute('namespacedName', new Name('Ns\\Foo'));
    expect(ClassSymbol::fqcnFromResolvedName($n))->toBe('Ns\\Foo');
});

it('uses FullyQualified nodes directly', function () {
    $n = new FullyQualified('X\\Y');
    expect(ClassSymbol::fqcnFromResolvedName($n))->toBe('X\\Y');
});

it('falls back to namespace and short name', function () {
    $n = new Name('Foo');
    expect(ClassSymbol::fqcnFromResolvedName($n, 'Ns'))->toBe('Ns\\Foo');
});

it('mangles fqcn for LLVM identifiers', function () {
    expect(ClassSymbol::mangle('A\\B'))->toBe('A_B');
});

it('builds LLVM method symbols', function () {
    expect(ClassSymbol::llvmMethodSymbol('A\\B', '__construct'))->toBe('A_B___construct');
    expect(ClassSymbol::llvmMethodSymbol('A\\B', 'run'))->toBe('A_B_run');
});

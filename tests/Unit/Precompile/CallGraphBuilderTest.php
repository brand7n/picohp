<?php

declare(strict_types=1);

use App\PicoHP\Precompile\CallGraphBuilder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PhpVersion;

function parseAndResolve(string $code): array
{
    $lexer = new \PhpParser\Lexer();
    $parser = new \PhpParser\Parser\Php8($lexer, PhpVersion::getNewestSupported());
    $ast = $parser->parse($code);
    assert($ast !== null);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    return $traverser->traverse($ast);
}

it('extracts static calls and new expressions', function () {
    $code = '<?php
namespace App;

class Foo {
    public static function create(): self {
        return new self();
    }
}

class Bar {
    public function run(): void {
        $f = Foo::create();
    }
}
';
    $ast = parseAndResolve($code);
    $builder = new CallGraphBuilder();
    $result = $builder->extractFromAst($ast, '/test.php');

    expect($result->classFiles)->toHaveKey('App\Foo');
    expect($result->classFiles)->toHaveKey('App\Bar');

    // Bar::run calls Foo::create
    $barEdges = $result->edges['App\Bar']['run'] ?? [];
    $hasStaticCall = false;
    foreach ($barEdges as [$cls, $method]) {
        if ($cls === 'App\Foo' && $method === 'create') {
            $hasStaticCall = true;
        }
    }
    expect($hasStaticCall)->toBeTrue();
});

it('follows $this method calls within a class', function () {
    $code = '<?php
class Helper {
    public function a(): void {
        $this->b();
    }
    public function b(): void {
        echo "hi";
    }
    public function unreachable(): void {
        echo "never";
    }
}
';
    $ast = parseAndResolve($code);
    $builder = new CallGraphBuilder();
    $result = $builder->extractFromAst($ast, '/test.php');

    // BFS from Helper::a reaches Helper::b via $this->b().
    // Conservative: once a class is reachable, ALL its declared methods are included
    // (without full type inference we can't prove which methods are never called).
    $reachable = $result->reachableFrom([['Helper', 'a']]);
    expect($reachable)->toHaveKey('Helper');
    expect($reachable['Helper'])->toHaveKey('a');
    expect($reachable['Helper'])->toHaveKey('b');
    // unreachable IS included (conservative — all declared methods on reachable class)
    expect($reachable['Helper'])->toHaveKey('unreachable');
});

it('tracks class hierarchy edges (extends, implements)', function () {
    $code = '<?php
namespace Lib;

interface Printable {
    public function print(): void;
}

abstract class Base {
    abstract public function id(): int;
}

class Concrete extends Base implements Printable {
    public function id(): int { return 1; }
    public function print(): void { echo $this->id(); }
}
';
    $ast = parseAndResolve($code);
    $builder = new CallGraphBuilder();
    $result = $builder->extractFromAst($ast, '/test.php');

    // Concrete references Base and Printable via __class__ edges
    $classEdges = $result->edges['Lib\Concrete']['__class__'] ?? [];
    $parentNames = array_map(fn ($e) => $e[0], $classEdges);
    expect($parentNames)->toContain('Lib\Base');
    expect($parentNames)->toContain('Lib\Printable');
});

it('computes reachable files from entrypoint', function () {
    $code1 = '<?php
namespace App;

class Entry {
    public function main(): void {
        $h = new \Lib\Used();
        $h->go();
    }
}
';
    $code2 = '<?php
namespace Lib;

class Used {
    public function go(): void { echo "used"; }
}

class Unused {
    public function skip(): void { echo "skip"; }
}
';
    $ast1 = parseAndResolve($code1);
    $ast2 = parseAndResolve($code2);
    $builder = new CallGraphBuilder();
    $r1 = $builder->extractFromAst($ast1, '/app/Entry.php');
    $r2 = $builder->extractFromAst($ast2, '/lib/classes.php');
    $r1->merge($r2);

    $reachable = $r1->reachableFrom([['App\Entry', 'main']]);
    $files = $r1->reachableFiles($reachable);

    expect($files)->toContain('/app/Entry.php');
    expect($files)->toContain('/lib/classes.php');

    // Unused class methods should not be reachable
    expect($reachable)->not->toHaveKey('Lib\Unused');
    // But Used::go should be
    expect($reachable['Lib\Used'])->toHaveKey('go');
});

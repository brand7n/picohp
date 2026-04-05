<?php

declare(strict_types=1);

use App\Cli\BuildOptions;

it('parses defaults', function () {
    $o = BuildOptions::parse([]);
    expect($o->debug)->toBeFalse();
    expect($o->filename)->toBeNull();
    expect($o->out)->toBe('a.out');
});

it('parses flags and positionals', function () {
    $o = BuildOptions::parse(['-d', '--shared-lib', '--precompile-plan', '-v', '-vv', 'file.php']);
    expect($o->debug)->toBeTrue();
    expect($o->sharedLib)->toBeTrue();
    expect($o->precompilePlan)->toBeTrue();
    expect($o->verbosity)->toBe(2);
    expect($o->filename)->toBe('file.php');
});

it('parses --override-class with two arguments', function () {
    $o = BuildOptions::parse(['--override-class', 'Foo\\Bar', 'stubs/Bar.php', 'src/']);
    expect($o->classPathOverrides)->toBe(['Foo\\Bar' => 'stubs/Bar.php']);
    expect($o->filename)->toBe('src/');
});

it('rejects --override-class without path', function () {
    BuildOptions::parse(['--override-class', 'Foo\\Bar']);
})->throws(\InvalidArgumentException::class);

it('parses --out=value and --out value', function () {
    expect(BuildOptions::parse(['--out=x.out'])->out)->toBe('x.out');
    expect(BuildOptions::parse(['--out', 'y.out'])->out)->toBe('y.out');
});

it('parses --with-opt-ll and --entry variants', function () {
    expect(BuildOptions::parse(['--with-opt-ll=1'])->withOptLl)->toBe('1');
    expect(BuildOptions::parse(['--with-opt-ll', '2'])->withOptLl)->toBe('2');
    expect(BuildOptions::parse(['--with-opt-ll'])->withOptLl)->toBe('off');
    expect(BuildOptions::parse(['--entry=src/entry.php'])->entry)->toBe('src/entry.php');
    expect(BuildOptions::parse(['--entry', 'other.php'])->entry)->toBe('other.php');
});

it('parses -vvv', function () {
    expect(BuildOptions::parse(['-vvv'])->verbosity)->toBe(2);
});

it('rejects unknown options', function () {
    BuildOptions::parse(['--nope']);
})->throws(\InvalidArgumentException::class);

it('rejects too many positionals', function () {
    BuildOptions::parse(['a.php', 'b.php']);
})->throws(\InvalidArgumentException::class);

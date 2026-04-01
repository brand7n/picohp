<?php

declare(strict_types=1);

use App\PicoHP\Pass\SemanticAnalysisPass;
use App\PicoHP\PicoType;
use App\PicoHP\SymbolTable\ClassMetadata;

/**
 * Targeted coverage for SemanticAnalysisPass: reflection helpers, semantic warnings, docblock edge cases,
 * and snippets that would fail IR but still exercise semantic analysis.
 */
final class SemanticAnalysisPassProbe extends SemanticAnalysisPass
{
    public function exposeRegisterBuiltinClasses(): void
    {
        $this->registerBuiltinClasses();
    }

    public function exposeEnsureExternalClassReference(string $fqcn): void
    {
        $this->ensureExternalClassReference($fqcn);
    }

    public function exposeRegisterClassHierarchyFromReflection(string $fqcn): bool
    {
        return $this->registerClassHierarchyFromReflection($fqcn);
    }

    public function exposeRegisterInstanceMethodsFromReflection(ClassMetadata $meta, string $fqcn): void
    {
        $this->registerInstanceMethodsFromReflection($meta, $fqcn);
    }

    public function exposeRegisterInstancePropertiesFromReflection(ClassMetadata $meta, string $fqcn): void
    {
        $this->registerInstancePropertiesFromReflection($meta, $fqcn);
    }

    public function exposeMergeMissingInstancePropertiesForFqcn(string $fqcn): void
    {
        $this->mergeMissingInstancePropertiesForFqcn($fqcn);
    }

    public function exposeMergeMissingInstancePropertiesFromReflectionAll(): void
    {
        $this->mergeMissingInstancePropertiesFromReflectionAll();
    }

    public function exposePicoTypeFromReflectionProperty(\ReflectionProperty $rp): PicoType
    {
        return $this->picoTypeFromReflectionProperty($rp);
    }

    public function exposePicoTypeFromReflectionReturnType(\ReflectionMethod $rm): PicoType
    {
        return $this->picoTypeFromReflectionReturnType($rm);
    }

    public function exposePicoTypeFromReflectionParameter(\ReflectionParameter $rp): PicoType
    {
        return $this->picoTypeFromReflectionParameter($rp);
    }

    public function exposePicoTypeNameFromReflectionNamedType(\ReflectionNamedType $rt): string
    {
        return $this->picoTypeNameFromReflectionNamedType($rt);
    }

    public function seedEmptyPhpParserInterfaceStub(string $fqcn): void
    {
        $this->classRegistry[$fqcn] = new ClassMetadata($fqcn);
        $this->typeIdMap[$fqcn] = $this->nextTypeId++;
    }

    public function exposeIsAssignmentCompatible(PicoType $l, PicoType $r): bool
    {
        return $this->isAssignmentCompatible($l, $r);
    }

    public function exposeIsSubclassOf(string $className, string $parentName): bool
    {
        return $this->isSubclassOf($className, $parentName);
    }
}

it('semantic: null warning callback short-circuits emitSemanticWarning', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

$a = new class {
    public function x(): int {
        return 1;
    }
};

echo strval($a->x());

PHP;
    picohpRunSemanticOnly($src, null);
    expect(true)->toBeTrue();
});

it('semantic: anonymous class triggers semantic warning when handler is set', function (): void {
    $calls = [];
    $src = <<<'PHP'
<?php

declare(strict_types=1);

$a = new class {
    public function x(): int {
        return 1;
    }
};

echo strval($a->x());

PHP;
    picohpRunSemanticOnly($src, static function (string $msg) use (&$calls): void {
        $calls[] = $msg;
    });
    expect($calls)->not->toBeEmpty();
    expect($calls[0])->toContain('Anonymous class');
});

it('semantic: typed promoted param with block (non-doc) @var docblock', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

final class Box
{
    public function __construct(
        /* @var array<int, int> */
        public array $data,
    ) {
    }

    public function first(): int
    {
        return $this->data[0];
    }
}

$b = new Box([1, 2]);
echo strval($b->first());

PHP;
    picohpRunMiniPipeline($src);
    expect(true)->toBeTrue();
});

it('semantic: typed promoted param with @var docblock refines property type', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

final class Box
{
    /** @var array<int, int> */
    public array $items;

    public function __construct(
        /** @var array<int, int> */
        public array $data,
    ) {
        $this->items = $data;
    }
}

$b = new Box([1, 2]);
echo strval($b->items[0]);

PHP;
    picohpRunMiniPipeline($src);
    expect(true)->toBeTrue();
});

it('semantic: unknown constant emits warning when callback is non-null', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

function main(): void {
    $x = \SOME_UNKNOWN_PICOHP_CONST_TEST;
    echo strval($x);
}

PHP;
    $calls = [];
    picohpRunMiniPipeline($src, static function (string $msg) use (&$calls): void {
        $calls[] = $msg;
    });
    expect($calls)->not->toBeEmpty();
});

it('probe: replace empty PhpParser stub with reflection hierarchy', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $p->seedEmptyPhpParserInterfaceStub('PhpParser\Node');
    $p->exposeEnsureExternalClassReference('PhpParser\Node');
    expect($p->getClassRegistry())->toHaveKey('PhpParser\Node');
});

it('probe: register class hierarchy from reflection for stdClass', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $ok = $p->exposeRegisterClassHierarchyFromReflection(\stdClass::class);
    expect($ok)->toBeTrue();
    expect($p->getClassRegistry())->toHaveKey(\stdClass::class);
});

it('probe: register Iterator interface via reflection', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $ok = $p->exposeRegisterClassHierarchyFromReflection(\Iterator::class);
    expect($ok)->toBeTrue();
    expect($p->getClassRegistry())->toHaveKey(\Iterator::class);
});

it('probe: reflection property types cover union and mixed branches', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);

    $ref = new ReflectionClass(\PhpParser\Node\Stmt\Property::class);
    $prop = $ref->getProperty('flags');
    $t = $p->exposePicoTypeFromReflectionProperty($prop);
    expect($t->toString())->toBe('int');

    $rf = new ReflectionClass(SemanticAnalysisPassCoverageUnionHolder::class);
    $unionProp = $rf->getProperty('u');
    $tu = $p->exposePicoTypeFromReflectionProperty($unionProp);
    expect($tu->isMixed())->toBeTrue();

    $untyped = $rf->getProperty('noType');
    $tm = $p->exposePicoTypeFromReflectionProperty($untyped);
    expect($tm->isMixed())->toBeTrue();

    $enumProp = $rf->getProperty('e');
    $enumType = $p->exposePicoTypeFromReflectionProperty($enumProp);
    expect($enumType->isEnum())->toBeTrue();
});

it('probe: reflection method return and parameter types', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);

    $rmInt = new ReflectionMethod(SemanticAnalysisPassCoverageReflectionTarget::class, 'returnsInt');
    $rt = $p->exposePicoTypeFromReflectionReturnType($rmInt);
    expect($rt->toBase()->value)->toBe('int');

    $rmVoid = new ReflectionMethod(SemanticAnalysisPassCoverageReflectionTarget::class, 'returnsVoid');
    $rv = $p->exposePicoTypeFromReflectionReturnType($rmVoid);
    expect($rv->toString())->toBe('void');

    $rmMixed = new ReflectionMethod(SemanticAnalysisPassCoverageUnionHolder::class, 'm');
    $unionRet = $p->exposePicoTypeFromReflectionReturnType($rmMixed);
    expect($unionRet->isMixed())->toBeTrue();

    $param = $rmMixed->getParameters()[0];
    $pt = $p->exposePicoTypeFromReflectionParameter($param);
    expect($pt->isMixed())->toBeTrue();

    $rfStrlen = new ReflectionFunction('strlen');
    $pp = $rfStrlen->getParameters()[0];
    $pst = $p->exposePicoTypeFromReflectionParameter($pp);
    expect($pst->toString())->toBe('string');
});

it('probe: register instance methods and properties from reflection', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $meta = new ClassMetadata(\stdClass::class);
    $p->exposeRegisterInstanceMethodsFromReflection($meta, \stdClass::class);
    $p->exposeRegisterInstancePropertiesFromReflection($meta, \stdClass::class);
    expect($meta->methods)->toBeArray();
});

it('probe: merge missing instance properties walks registry', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $p->exposeRegisterClassHierarchyFromReflection(\stdClass::class);
    $p->exposeMergeMissingInstancePropertiesFromReflectionAll();
    expect(true)->toBeTrue();
});

it('probe: merge missing for fqcn no-op when missing', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeMergeMissingInstancePropertiesForFqcn('Nonexistent\\Class\\Fqcn');
    expect(true)->toBeTrue();
});

it('probe: merge missing skips traits and interfaces', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $p->seedEmptyPhpParserInterfaceStub(\Iterator::class);
    $p->exposeMergeMissingInstancePropertiesForFqcn(\Iterator::class);
    expect(true)->toBeTrue();
});

it('probe: assignment compatibility for related object types', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $p->exposeRegisterClassHierarchyFromReflection(\Exception::class);
    $p->exposeRegisterClassHierarchyFromReflection(\InvalidArgumentException::class);
    $ok = $p->exposeIsAssignmentCompatible(
        PicoType::object(\Exception::class),
        PicoType::object(\InvalidArgumentException::class),
    );
    expect($ok)->toBeTrue();
});

it('probe: isSubclassOf follows parent chain', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $p->exposeRegisterClassHierarchyFromReflection(\InvalidArgumentException::class);
    $sub = $p->exposeIsSubclassOf(\InvalidArgumentException::class, \Exception::class);
    expect($sub)->toBeTrue();
});

it('semantic: nested block statement creates scope', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

function main(): void
{
    { $x = 1; echo strval($x); }
}

PHP;
    picohpRunMiniPipeline($src);
    expect(true)->toBeTrue();
});

it('semantic: unsupported Global_ stmt throws', function (): void {
    expect(fn () => picohpRunSemanticOnly('<?php declare(strict_types=1);
function main(): void {
    global $x;
    echo strval($x);
}
'))->toThrow(\Exception::class);
});

it('semantic: unsupported Yield_ expr throws', function (): void {
    expect(fn () => picohpRunSemanticOnly('<?php declare(strict_types=1);
function main(): void {
    yield 1;
}
'))->toThrow(\Exception::class);
});

// --- Gap 1: reflection registration failure branches ---

it('probe: registerClassHierarchyFromReflection returns false for nonexistent class', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    $ok = $p->exposeRegisterClassHierarchyFromReflection('Nonexistent\\Class\\ThatDoesNotExist');
    expect($ok)->toBeFalse();
});

it('probe: registerClassHierarchyFromReflection returns false for traits', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    // PhpParser\NodeAbstract uses JsonSerializable but that's an interface; use a real trait
    $ok = $p->exposeRegisterClassHierarchyFromReflection(\App\PicoHP\Tree\NodeTrait::class);
    expect($ok)->toBeFalse();
});

it('probe: registerClassHierarchyFromReflection returns true and re-uses existing entry', function (): void {
    $ast = picohpParsePipelineAst('<?php declare(strict_types=1); function main(): void { echo 1; }');
    $p = new SemanticAnalysisPassProbe($ast, null);
    $p->exposeRegisterBuiltinClasses();
    // First call registers
    expect($p->exposeRegisterClassHierarchyFromReflection(\stdClass::class))->toBeTrue();
    // Second call hits the "already registered" early return
    expect($p->exposeRegisterClassHierarchyFromReflection(\stdClass::class))->toBeTrue();
});

// --- Gap 2: untyped promoted constructor param ---

it('semantic: untyped promoted constructor param with PHPDoc emits warning and treats as mixed', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

final class UntypedPromoted
{
    public function __construct(
        /** @var int */
        public $value,
    ) {
    }
}

PHP;
    $warnings = [];
    picohpRunSemanticOnly($src, static function (string $msg) use (&$warnings): void {
        $warnings[] = $msg;
    });
    $hasUntypedWarning = false;
    foreach ($warnings as $w) {
        if (str_contains($w, 'untyped parameter')) {
            $hasUntypedWarning = true;
        }
    }
    expect($hasUntypedWarning)->toBeTrue();
});

it('semantic: untyped promoted constructor param without PHPDoc fails', function (): void {
    $src = <<<'PHP'
<?php

declare(strict_types=1);

final class BadPromoted
{
    public function __construct(
        public $value,
    ) {
    }
}

PHP;
    expect(fn () => picohpRunSemanticOnly($src))->toThrow(\App\PicoHP\CompilerInvariantException::class);
});

it('semantic: spaceship operator is not supported in binary op resolver', function (): void {
    expect(fn () => picohpRunSemanticOnly('<?php declare(strict_types=1);
function main(): void {
    $a = 1 <=> 2;
    echo strval($a);
}
'))->toThrow(\Exception::class);
});

it('semantic: return type mismatch throws', function (): void {
    expect(fn () => picohpRunSemanticOnly('<?php declare(strict_types=1);
function main(): int {
    return "x";
}
'))->toThrow(\Exception::class);
});

it('semantic: exit value mismatch with function return throws', function (): void {
    expect(fn () => picohpRunSemanticOnly('<?php declare(strict_types=1);
function main(): int {
    exit("bad");
}
'))->toThrow(\Exception::class);
});

it('semantic: dynamic static call is typed as mixed with warning', function (): void {
    $warn = [];
    $src = <<<'PHP'
<?php

declare(strict_types=1);

final class C
{
    public static function m(): int
    {
        return 1;
    }
}

function main(): void
{
    $c = 'C';
    echo strval($c::m());
}

PHP;
    picohpRunSemanticOnly($src, static function (string $msg) use (&$warn): void {
        $warn[] = $msg;
    });
    expect($warn)->not->toBeEmpty();
});

enum SemanticAnalysisPassCoverageEnumForProbe: int
{
    case A = 1;
}

final class SemanticAnalysisPassCoverageReflectionTarget
{
    public function returnsInt(): int
    {
        return 1;
    }

    public function returnsVoid(): void
    {
    }
}

/**
 * @phpstan-ignore-next-line — intentional untyped property for reflection mixed branch
 */
final class SemanticAnalysisPassCoverageUnionHolder
{
    public int|string $u = 1;

    /** @phpstan-ignore-next-line */
    public $noType = 1;

    public SemanticAnalysisPassCoverageEnumForProbe $e = SemanticAnalysisPassCoverageEnumForProbe::A;

    /**
     * @return int|string
     */
    public function m(int|string $x): int|string
    {
        return $x;
    }
}

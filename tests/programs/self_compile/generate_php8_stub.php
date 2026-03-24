#!/usr/bin/env php
<?php

/**
 * Generates a compilable version of php-parser's Php8.php by:
 * 1. Transforming closures into a switch statement
 * 2. Adding stub classes for ParserAbstract and Node types
 * 3. Stripping namespaces
 *
 * Output: tests/programs/self_compile/php8_transformed.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

$code = file_get_contents(__DIR__ . '/../../../vendor/nikic/php-parser/lib/PhpParser/Parser/Php8.php');
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$ast = $parser->parse($code);
$printer = new PhpParser\PrettyPrinter\Standard();

// Extract closure cases
$visitor = new class ($printer) extends PhpParser\NodeVisitorAbstract {
    private PhpParser\PrettyPrinter\Standard $printer;

    /** @var array<int, string> */
    public array $cases = [];

    public function __construct(PhpParser\PrettyPrinter\Standard $p)
    {
        $this->printer = $p;
    }

    public function enterNode(PhpParser\Node $node): null
    {
        if ($node instanceof PhpParser\Node\Expr\ArrayItem
            && $node->key instanceof PhpParser\Node\Scalar\Int_
            && $node->value instanceof PhpParser\Node\Expr\Closure) {
            $body = "";
            foreach ($node->value->stmts as $stmt) {
                $body .= $this->printer->prettyPrint([$stmt]) . "\n";
            }
            $body = str_replace('$self->', '$this->', $body);
            $body = str_replace('($self,', '($this,', $body);
            $this->cases[$node->key->value] = $body;
        }
        return null;
    }
};

$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor($visitor);
$traverser->traverse($ast);

// Build switch method
$switch = "    protected function executeReduce(int \$rule, int \$stackPos): void\n    {\n        switch (\$rule) {\n";
foreach ($visitor->cases as $num => $body) {
    $indented = "                " . str_replace("\n", "\n                ", rtrim($body));
    $switch .= "            case {$num}:\n{$indented}\n                break;\n";
}
$switch .= "            default:\n                if (\$this->ruleToLength[\$rule] > 0) {\n                    \$this->semValue = \$this->semStack[\$stackPos - \$this->ruleToLength[\$rule] + 1];\n                }\n                break;\n        }\n    }\n";

// Replace initReduceCallbacks in original
$lines = explode("\n", $code);
$output = [];
$inMethod = false;
$braceDepth = 0;
$replaced = false;
foreach ($lines as $line) {
    if (! $replaced && str_contains($line, 'protected function initReduceCallbacks(): void {')) {
        $inMethod = true;
        $braceDepth = 1;
        $output[] = $switch;
        $output[] = '    protected function initReduceCallbacks(): void {}';
        continue;
    }
    if ($inMethod) {
        $braceDepth += substr_count($line, '{') - substr_count($line, '}');
        if ($braceDepth <= 0) {
            $inMethod = false;
            $replaced = true;
        }
        continue;
    }
    $output[] = $line;
}

$transformed = implode("\n", $output);

// Strip namespace and use statements
$transformed = preg_replace('/^<\?php\s*/', '', $transformed);
assert(is_string($transformed));
$transformed = preg_replace('/^namespace\s+[^;]+;\s*$/m', '', $transformed);
assert(is_string($transformed));
$transformed = preg_replace('/^use\s+[^;]+;\s*$/m', '', $transformed);
assert(is_string($transformed));
$transformed = preg_replace('/^declare\s*\([^)]+\)\s*;\s*$/m', '', $transformed);
assert(is_string($transformed));

// Fix class extends
$transformed = str_replace('extends \PhpParser\ParserAbstract', 'extends ParserAbstract', $transformed);

// Strip remaining namespace prefixes
$transformed = preg_replace('/\\\\?PhpParser\\\\(Node\\\\)?/', '', $transformed);
assert(is_string($transformed));
$transformed = preg_replace('/\\\\?PhpParser\\\\/', '', $transformed);
assert(is_string($transformed));

// Collect all Node class names used in new expressions
/** @var array<int, string> $m */
$m = [];
preg_match_all('/new ([A-Za-z_]+\\\\[A-Za-z_\\\\]+)/', $transformed, $m);
$nodeClasses = array_unique($m[1]);
sort($nodeClasses);

// Strip backslashes from Node class names (Stmt\Class_ -> Stmt_Class_)
foreach ($nodeClasses as $cls) {
    $flat = str_replace('\\', '_', $cls);
    $transformed = str_replace("new {$cls}", "new {$flat}", $transformed);
    $transformed = str_replace("({$cls} ", "({$flat} ", $transformed);
}

// Fix remaining qualified names (Type\Cast\Int_ -> Type_Cast_Int_ etc.)
// Only replace backslashes that are part of namespace separators (letter\letter)
$transformed = preg_replace('/([A-Za-z_])\\\\([A-Za-z_])/', '$1_$2', $transformed);
assert(is_string($transformed));
// Run twice for triple-deep names like Expr\BinaryOp\Plus
$transformed = preg_replace('/([A-Za-z_])\\\\([A-Za-z_])/', '$1_$2', $transformed);
assert(is_string($transformed));

// Collect static method calls on Node classes (Class::method( pattern).
// Runs after namespace flattening, so all class names are simple identifiers (Foo_Bar).
// May match false positives in strings/comments — acceptable for stub generation.
/** @var array<int, string> $staticMatches */
$staticMatches = [];
preg_match_all('/([A-Za-z_]+)::([a-zA-Z_]+)\(/', $transformed, $staticMatches);
/** @var array<string, array<string>> class => [methods] */
$staticMethods = [];
/** @var array<string, bool> */
$extraClasses = [];
foreach ($staticMatches[1] as $i => $className) {
    $method = $staticMatches[2][$i];
    if ($className === 'self' || $className === 'parent' || $className === 'Modifiers') {
        continue;
    }
    $staticMethods[$className][] = $method;
    if (str_contains($className, '_')) {
        $extraClasses[$className] = true;
    }
}
foreach ($staticMethods as $cls => $methods) {
    $staticMethods[$cls] = array_unique($methods);
}

// Build output file
$stubFile = "<?php\n\ndeclare(strict_types=1);\n\n";

// Add base stubs
$stubFile .= <<<'STUBS'
class RuntimeException extends Exception {}
interface Parser {}

class Error extends RuntimeException {
    public function __construct(string $message, int $line = -1) { parent::__construct($message); }
}

class Token {
    public int $id = 0;
    public string $text = '';
    public int $line = 0;
    public int $pos = 0;
}

class Comment {
    public function __construct(string $text) {}
}

class Name { public function __construct(mixed ...$args) {} }

class Modifiers {
    public const PUBLIC = 1;
    public const PROTECTED = 2;
    public const PRIVATE = 4;
    public const STATIC = 8;
    public const ABSTRACT = 16;
    public const FINAL = 32;
    public const READONLY = 64;
}


STUBS;

// Add Node class stubs with static methods where needed
foreach ($nodeClasses as $cls) {
    $flat = str_replace('\\', '_', $cls);
    $methods = "public function __construct(mixed ...\$args) {}";
    if (isset($staticMethods[$flat])) {
        foreach ($staticMethods[$flat] as $method) {
            $methods .= " public static function {$method}(mixed ...\$args): mixed { return null; }";
        }
    }
    $stubFile .= "class {$flat} { {$methods} }\n";
}
// Add extra classes found in static calls that aren't in nodeClasses
foreach ($extraClasses as $cls => $_) {
    $alreadyExists = false;
    foreach ($nodeClasses as $nc) {
        if (str_replace('\\', '_', $nc) === $cls) {
            $alreadyExists = true;
            break;
        }
    }
    if (! $alreadyExists) {
        $methods = "public function __construct(mixed ...\$args) {}";
        if (isset($staticMethods[$cls])) {
            foreach ($staticMethods[$cls] as $method) {
                $methods .= " public static function {$method}(mixed ...\$args): mixed { return null; }";
            }
        }
        $stubFile .= "class {$cls} { {$methods} }\n";
    }
}

$stubFile .= "\n";

// Add ParserAbstract stub
$stubFile .= <<<'PARSER'
abstract class ParserAbstract implements Parser {
    protected int $tokenPos = 0;
    /** @var array<int, Token> */
    protected array $tokens = [];
    /** @var array<int, int> */
    protected array $actionBase = [];
    /** @var array<int, int> */
    protected array $actionCheck = [];
    /** @var array<int, int> */
    protected array $actionDefault = [];
    /** @var array<int, int> */
    protected array $actionTable = [];
    /** @var array<int, int> */
    protected array $gotoBase = [];
    /** @var array<int, int> */
    protected array $gotoCheck = [];
    /** @var array<int, int> */
    protected array $gotoDefault = [];
    /** @var array<int, int> */
    protected array $gotoTable = [];
    /** @var array<int, int> */
    protected array $ruleToLength = [];
    /** @var array<int, int> */
    protected array $ruleToNonTerminal = [];
    /** @var mixed */
    protected $semValue;
    /** @var mixed */
    protected $semStack;
    /** @var array<int, int> */
    protected array $tokenStartStack = [];
    /** @var array<int, int> */
    protected array $tokenEndStack = [];
    /** @var array<int, int> */
    protected array $tokenToSymbol = [];
    protected int $tokenToSymbolMapSize = 0;
    protected int $invalidSymbol = 0;
    protected int $numNonLeafStates = 0;
    /** @var mixed */
    protected $phpVersion;
    protected int $errorState = 0;
    /** @var mixed */
    protected $createdArrays;
    /** @var mixed */
    protected $parenthesizedArrowFunctions;

    abstract protected function initReduceCallbacks(): void;

    /** @return array<int, string> */
    public function getAttributes(int $s, int $e): mixed { return null; }
    /** @param array<int, string> $stmts
     *  @return array<int, string> */
    public function handleNamespaces(array $stmts): array { return []; }
    public function emitError(Error $e): void {}
    public function handleBuiltinTypes(mixed $n): void {}
    public function getFloatCastKind(string $c): int { return 0; }
    public function getIntCastKind(string $c): int { return 0; }
    public function getBoolCastKind(string $c): int { return 0; }
    public function getStringCastKind(string $c): int { return 0; }
    public function parseLNumber(string $s, array $a, bool $o = false): mixed { return null; }
    public function parseNumString(string $s, array $a): void {}
    public function stripIndentation(string $s, int $l, string $c, bool $ns, bool $ne, array $a): string { return ''; }
    public function parseDocString(string $st, mixed $c, string $et, array $a, array $ea, bool $u): mixed { return null; }
    public function createCommentFromToken(Token $t, int $p): Comment { return new Comment(''); }
    public function getCommentBeforeToken(int $p): ?Comment { return null; }
    public function maybeCreateZeroLengthNop(int $p): mixed { return null; }
    public function maybeCreateNop(int $s, int $e): mixed { return null; }
    public function handleHaltCompiler(): string { return ''; }
    public function inlineHtmlHasLeadingNewline(int $p): bool { return false; }
    /** @return array<int, string> */
    public function createEmptyElemAttributes(int $p): array { return []; }
    public function fixupArrayDestructuring(mixed $n): mixed { return $n; }
    public function postprocessList(mixed $n): void {}
    public function fixupAlternativeElse(mixed $n): void {}
    public function checkClassModifier(int $a, int $b, int $p): void {}
    public function checkModifier(int $a, int $b, int $p): void {}
    public function checkParam(mixed $n): void {}
    public function checkTryCatch(mixed $n): void {}
    public function checkNamespace(mixed $n): void {}
    public function checkClass(mixed $n, int $p): void {}
    public function checkInterface(mixed $n, int $p): void {}
    public function checkEnum(mixed $n, int $p): void {}
    public function checkClassMethod(mixed $n, int $p): void {}
    public function checkClassConst(mixed $n, int $p): void {}
    public function checkUseUse(mixed $n, int $p): void {}
    public function checkPropertyHooksForMultiProperty(mixed $p, int $h): void {}
    /** @param array<int, string> $h */
    public function checkEmptyPropertyHookList(array $h, int $p): void {}
    public function checkPropertyHook(mixed $h, ?int $p): void {}
    public function checkPropertyHookModifiers(int $a, int $b, int $p): void {}
    public function checkConstantAttributes(mixed $n): void {}
    public function checkPipeOperatorParentheses(mixed $n): void {}
    public function addPropertyNameToHooks(mixed $n): void {}
    /** @param array<int, string> $a */
    public function isSimpleExit(array $a): bool { return false; }
    /** @param array<int, string> $a
     *  @param array<int, string> $t */
    public function createExitExpr(string $n, int $p, array $a, array $t): mixed { return null; }
}


PARSER;

$stubFile .= "\n" . $transformed;

$outPath = __DIR__ . '/php8_transformed.php';
file_put_contents($outPath, $stubFile);
echo "Generated: {$outPath}\n";
echo "Size: " . strlen($stubFile) . " bytes\n";
echo "Node class stubs: " . count($nodeClasses) . "\n";

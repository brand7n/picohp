#!/usr/bin/env php
<?php

/**
 * Extracts real table data from nikic/php-parser's Php8 and generates
 * a compilable file that exercises the actual LR parser table lookups.
 *
 * Tied to php-parser 5.x internal property names on ParserAbstract/Php8.
 * If upstream renames properties, this generator will need updating.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

$obj = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
assert($obj instanceof PhpParser\Parser\Php8, 'Expected Php8 parser instance from factory');
$parserClass = new ReflectionClass(PhpParser\Parser\Php8::class);
$abstractClass = new ReflectionClass(PhpParser\ParserAbstract::class);

function getProperty(ReflectionClass $child, ReflectionClass $parent, object $obj, string $name): mixed
{
    try {
        $prop = $child->getProperty($name);
    } catch (ReflectionException) {
        $prop = $parent->getProperty($name);
    }
    return $prop->getValue($obj);
}

function exportArray(string $name, array $arr): string
{
    $items = implode(', ', $arr);
    return "        \$this->{$name} = [{$items}];";
}

$tables = ['actionBase', 'action', 'actionCheck', 'actionDefault',
           'gotoBase', 'goto', 'gotoCheck', 'gotoDefault',
           'tokenToSymbol', 'ruleToLength', 'ruleToNonTerminal'];

$data = [];
foreach ($tables as $t) {
    $data[$t] = getProperty($parserClass, $abstractClass, $obj, $t);
}

// Also get scalar properties
$scalars = [
    'tokenToSymbolMapSize' => getProperty($parserClass, $abstractClass, $obj, 'tokenToSymbolMapSize'),
    'actionTableSize' => getProperty($parserClass, $abstractClass, $obj, 'actionTableSize'),
    'gotoTableSize' => getProperty($parserClass, $abstractClass, $obj, 'gotoTableSize'),
    'invalidSymbol' => getProperty($parserClass, $abstractClass, $obj, 'invalidSymbol'),
    'defaultAction' => getProperty($parserClass, $abstractClass, $obj, 'defaultAction'),
    'unexpectedTokenRule' => getProperty($parserClass, $abstractClass, $obj, 'unexpectedTokenRule'),
    'numNonLeafStates' => getProperty($parserClass, $abstractClass, $obj, 'numNonLeafStates'),
];

// Build output
$out = "<?php\n\ndeclare(strict_types=1);\n\n";

$out .= <<<'ABSTRACT'
/**
 * Real LR parser table lookup extracted from nikic/php-parser Php8.
 * Uses the actual action/goto tables and lookup algorithm.
 */
class Php8Tables
{
    /** @var array<int, int> */
    protected array $actionBase = [];
    /** @var array<int, int> */
    protected array $action = [];
    /** @var array<int, int> */
    protected array $actionCheck = [];
    /** @var array<int, int> */
    protected array $actionDefault = [];
    /** @var array<int, int> */
    protected array $gotoBase = [];
    /** @var array<int, int> */
    protected array $goto = [];
    /** @var array<int, int> */
    protected array $gotoCheck = [];
    /** @var array<int, int> */
    protected array $gotoDefault = [];
    /** @var array<int, int> */
    protected array $tokenToSymbol = [];
    /** @var array<int, int> */
    protected array $ruleToLength = [];
    /** @var array<int, int> */
    protected array $ruleToNonTerminal = [];

    protected int $tokenToSymbolMapSize = 0;
    protected int $actionTableSize = 0;
    protected int $gotoTableSize = 0;
    protected int $invalidSymbol = 0;
    protected int $defaultAction = 0;
    protected int $unexpectedTokenRule = 0;
    protected int $numNonLeafStates = 0;

    public function __construct()
    {

ABSTRACT;

// Add table initializers
foreach ($tables as $t) {
    $out .= exportArray($t, $data[$t]) . "\n";
}
foreach ($scalars as $name => $val) {
    $out .= "        \$this->{$name} = {$val};\n";
}

$out .= "    }\n\n";

// Add the real lookup methods from ParserAbstract
$out .= <<<'METHODS'
    /**
     * Map a PHP token ID to the parser's internal symbol number.
     * This is the real algorithm from ParserAbstract.
     */
    public function mapTokenToSymbol(int $tokenId): int
    {
        if ($tokenId >= $this->tokenToSymbolMapSize) {
            return $this->invalidSymbol;
        }
        return $this->tokenToSymbol[$tokenId];
    }

    /**
     * Look up action for (state, symbol) in the LR action table.
     * This is the real algorithm from ParserAbstract.
     */
    public function lookupAction(int $state, int $symbol): int
    {
        /** @var int $idx */
        $idx = $this->actionBase[$state] + $symbol;
        if ($idx >= 0 && $idx < $this->actionTableSize
            && $this->actionCheck[$idx] === $state) {
            return $this->action[$idx];
        }
        return $this->actionDefault[$state];
    }

    /**
     * Look up goto target for (state, nonTerminal) in the goto table.
     * This is the real algorithm from ParserAbstract.
     */
    public function lookupGoto(int $state, int $nonTerminal): int
    {
        /** @var int $idx */
        $idx = $this->gotoBase[$nonTerminal] + $state;
        if ($idx >= 0 && $idx < $this->gotoTableSize
            && $this->gotoCheck[$idx] === $nonTerminal) {
            return $this->goto[$idx];
        }
        return $this->gotoDefault[$nonTerminal];
    }
}

METHODS;

// Add test function and main
$out .= <<<'TEST'

/**
 * Exercise the real parser tables: map tokens, look up actions and gotos.
 * Returns a checksum derived from real table lookups.
 */
function php8_table_test(): int
{
    /** @var Php8Tables $p */
    $p = new Php8Tables();

    /** @var int $checksum */
    $checksum = 0;

    // Map some real PHP token IDs to symbols
    /** @var int $tok */
    $tok = 0;
    while ($tok < 50) {
        /** @var int $sym */
        $sym = $p->mapTokenToSymbol($tok);
        $checksum = $checksum + $sym;
        $tok = $tok + 1;
    }

    // Look up actions for first 20 states with symbol 0
    /** @var int $state */
    $state = 0;
    while ($state < 20) {
        /** @var int $act */
        $act = $p->lookupAction($state, 1);
        $checksum = $checksum + $act;
        $state = $state + 1;
    }

    // Look up gotos for first 10 states with non-terminal 0
    $state = 0;
    while ($state < 10) {
        /** @var int $gt */
        $gt = $p->lookupGoto($state, 1);
        $checksum = $checksum + $gt;
        $state = $state + 1;
    }

    return $checksum;
}

echo php8_table_test();
echo "\n";

TEST;

$outPath = __DIR__ . '/php8_tables_ffi.php';
file_put_contents($outPath, $out);
echo "Generated: {$outPath}\n";
echo "Tables: " . array_sum(array_map('count', $data)) . " total entries\n";

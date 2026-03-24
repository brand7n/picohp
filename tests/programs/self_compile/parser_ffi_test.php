<?php

declare(strict_types=1);

/**
 * Minimal parser-like structure for FFI test.
 * Exercises: inheritance, array properties, table lookups — the same
 * patterns used by the Php8 parser's table-driven state machine.
 */

class ParserBase
{
    /** @var array<int, int> */
    protected array $actionBase = [];

    /** @var array<int, int> */
    protected array $actionTable = [];

    /** @var array<int, int> */
    protected array $actionCheck = [];

    /** @var array<int, int> */
    protected array $actionDefault = [];

    protected int $errorState = 0;

    public function __construct()
    {
        $this->actionBase = [0, 3, 6];
        $this->actionTable = [10, 20, 30, 40, 50, 60, 70, 80, 90];
        $this->actionCheck = [0, 0, 0, 1, 1, 1, 2, 2, 2];
        $this->actionDefault = [99, 98, 97];
        $this->errorState = -1;
    }

    public function lookupAction(int $state, int $symbol): int
    {
        /** @var int $base */
        $base = $this->actionBase[$state];
        /** @var int $idx */
        $idx = $base + $symbol;
        if ($this->actionCheck[$idx] === $state) {
            return $this->actionTable[$idx];
        }
        return $this->actionDefault[$state];
    }
}

class MiniParser extends ParserBase
{
    protected array $actionBase;
    protected array $actionTable;
    protected array $actionCheck;
    protected array $actionDefault;

    public function __construct()
    {
        parent::__construct();
    }

    public function runLookups(): int
    {
        /** @var int $sum */
        $sum = 0;
        /** @var int $s */
        $s = 0;
        while ($s < 3) {
            /** @var int $sym */
            $sym = 0;
            while ($sym < 3) {
                $sum = $sum + $this->lookupAction($s, $sym);
                $sym = $sym + 1;
            }
            $s = $s + 1;
        }
        return $sum;
    }
}

function parser_ffi_test(): int
{
    /** @var MiniParser $p */
    $p = new MiniParser();
    return $p->runLookups();
}

echo parser_ffi_test();
echo "\n";

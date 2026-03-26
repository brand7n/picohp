<?php

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class PicoHPData
{
    protected Scope $scope;
    public ?Symbol $symbol = null;
    public bool $lVal = false;
    public int $mycount = 0;
    public static int $count = 1;

    public function __construct(Scope $scope)
    {
        $this->scope = $scope;
        $this->mycount = self::$count++; // TODO: reset every function, hash w/ name, what???
    }

    public function setScope(Scope $scope): void
    {
        $this->scope = $scope;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function getValue(): ValueAbstract
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        $callerFuncRaw = $caller['function'] ?? null;
        $callerFileRaw = $caller['file'] ?? null;
        $callerLineRaw = $caller['line'] ?? null;
        $callerFunc = is_string($callerFuncRaw) ? $callerFuncRaw : 'unknown';
        $callerFile = is_string($callerFileRaw) ? basename($callerFileRaw) : 'unknown';
        $callerLine = is_int($callerLineRaw) ? $callerLineRaw : 0;
        \App\PicoHP\CompilerInvariant::check(
            $this->symbol !== null && $this->symbol->value !== null,
            "symbol/value missing in getValue() (caller: {$callerFunc} @ {$callerFile}:{$callerLine})"
        );
        return $this->symbol->value;
    }

    public function getSymbol(): Symbol
    {
        \App\PicoHP\CompilerInvariant::check($this->symbol !== null, 'picoHP symbol not initialized');
        return $this->symbol;
    }

    public static function getPData(\PhpParser\Node $node): PicoHPData
    {
        $pData = $node->getAttribute("picoHP");
        \App\PicoHP\CompilerInvariant::check($pData instanceof PicoHPData);
        return $pData;
    }
}

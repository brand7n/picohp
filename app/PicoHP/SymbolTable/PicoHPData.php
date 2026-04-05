<?php

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class PicoHPData
{
    protected Scope $scope;
    public ?Symbol $symbol = null;
    public bool $lVal = false;
    public int $mycount = 0;
    /** When true, IR gen emits abort() instead of compiling the body. */
    public bool $stubbed = false;
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
        \App\PicoHP\CompilerInvariant::check(
            $this->symbol !== null && $this->symbol->value !== null,
            'symbol/value missing in getValue()'
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
        $file = $node->getAttribute('pico_source_file', '?');
        $fileStr = is_string($file) ? $file : '?';
        \App\PicoHP\CompilerInvariant::check($pData instanceof PicoHPData, 'picoHP attribute missing on ' . get_class($node) . ' at ' . $fileStr . ':' . $node->getStartLine());
        return $pData;
    }
}

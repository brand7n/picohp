<?php

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class PicoHPData
{
    protected Scope $scope;
    public ?Symbol $symbol;
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
        assert($this->symbol !== null && $this->symbol->value !== null);
        return $this->symbol->value;
    }

    public function getSymbol(): Symbol
    {
        assert($this->symbol !== null);
        return $this->symbol;
    }

    public static function getPData(\PhpParser\Node $node): PicoHPData
    {
        $pData = $node->getAttribute("picoHP");
        assert($pData instanceof PicoHPData);
        return $pData;
    }
}

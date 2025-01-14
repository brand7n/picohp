<?php

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class PicoHPData
{
    protected Scope $scope;
    public ?Symbol $symbol;
    public bool $lVal = false;

    public function __construct(Scope $scope)
    {
        $this->scope = $scope;
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

    public static function getPData(\PhpParser\Node $node): PicoHPData
    {
        $pData = $node->getAttribute("picoHP");
        var_dump($node);
        var_dump($pData);
        assert($pData instanceof PicoHPData);
        return $pData;
    }
}

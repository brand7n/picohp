<?php

namespace App\PicoHP\LLVM;

class IRLine
{
    private string $text;
    private int $indent;
    private ?int $dbgRef;

    public function __construct(string $text = '', int $indent = 0, ?int $dbgRef = null)
    {
        $this->text = $text;
        $this->indent = $indent;
        $this->dbgRef = $dbgRef;
    }

    public function toString(): string
    {
        $line = str_repeat(' ', $this->indent * 4) . $this->text;
        if ($this->dbgRef !== null) {
            $line .= ", !dbg !{$this->dbgRef}";
        }
        return $line;
    }
}

<?php

namespace App\PicoHP\LLVM;

class IRLine
{
    private string $text;
    private int $indent;

    public function __construct(string $text = '', int $indent = 0)
    {
        $this->text = $text;
        $this->indent = $indent;
    }

    public function toString(): string
    {
        return str_repeat(' ', $this->indent * 4) . $this->text;
    }
}

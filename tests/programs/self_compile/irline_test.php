<?php

declare(strict_types=1);

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

$line1 = new IRLine('ret i32 0', 1);
echo $line1->toString();
echo "\n";

$line2 = new IRLine('entry:', 0);
echo $line2->toString();
echo "\n";

$line3 = new IRLine('br label %loop', 2);
echo $line3->toString();
echo "\n";

$empty = new IRLine('', 0);
echo $empty->toString();
echo "\n";

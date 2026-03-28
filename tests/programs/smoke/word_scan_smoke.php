<?php

declare(strict_types=1);

/**
 * Small non-trivial smoke target: two classes, nested while loops, str_contains, static method.
 */
final class WordScanner
{
    private int $pos = 0;

    /** @var string */
    private string $alpha;

    /** @var string */
    private string $alnum;

    private string $source;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->alpha = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';
        $this->alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789';
    }

    public function countWords(): int
    {
        $n = 0;
        $len = strlen($this->source);
        while ($this->pos < $len) {
            $ch = substr($this->source, $this->pos, 1);
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                $this->pos = $this->pos + 1;
            } elseif (str_contains($this->alpha, $ch)) {
                $n = $n + 1;
                $this->pos = $this->pos + 1;
                $inner = 1;
                while ($inner === 1) {
                    if ($this->pos >= $len) {
                        $inner = 0;
                    } else {
                        $ch2 = substr($this->source, $this->pos, 1);
                        if (str_contains($this->alnum, $ch2)) {
                            $this->pos = $this->pos + 1;
                        } else {
                            $inner = 0;
                        }
                    }
                }
            } else {
                $this->pos = $this->pos + 1;
            }
        }

        return $n;
    }
}

final class Report
{
    public static function line(string $label, int $value): void
    {
        echo $label;
        echo ': ';
        echo $value;
        echo "\n";
    }
}

$s1 = 'hello world foo';
$w1 = new WordScanner($s1);
Report::line('words_a', $w1->countWords());

$s2 = '  x y  z ';
$w2 = new WordScanner($s2);
Report::line('words_b', $w2->countWords());

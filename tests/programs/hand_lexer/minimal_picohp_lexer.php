<?php

declare(strict_types=1);

/**
 * Count word tokens; no break/continue — PicoHP IR gap on break in while.
 */
final class MinimalPicohpLexer
{
    private int $pos = 0;

    private string $source;

    /** @var string */
    private string $alpha;

    /** @var string */
    private string $alnum;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->alpha = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';
        $this->alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789';
    }

    public function countWordTokens(): int
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

$src = '<?php echo hello;';
$lex = new MinimalPicohpLexer($src);
echo $lex->countWordTokens();
echo "\n";

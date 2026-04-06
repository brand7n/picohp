<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

/**
 * Compat stub for self-compilation. Always uses native lexer since
 * the compiled binary doesn't have token_get_all.
 */
class TokenAdapter
{
    public static function useNativeLexer(): bool
    {
        return true;
    }
}

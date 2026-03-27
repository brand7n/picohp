<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

class TokenAdapter extends \PhpParser\Token
{
    /**
     * @return list<static>
     */
    public static function tokenize(string $code, int $flags = 0): array
    {
        // for now disable our native lexer
        // $lexer = new Lexer($code);
        // $tokens = $lexer->tokenize();
        // /** @var list<static> $phpTokens */
        // $phpTokens = [];
        // foreach ($tokens as $token) {
        //     $phpTokens[] = new static($token->type->value, $token->value, $token->line);
        // }
        // return $phpTokens;
        /** @var list<static> $tokens */
        $tokens = array_values(parent::tokenize($code, $flags));

        return $tokens;
    }
}

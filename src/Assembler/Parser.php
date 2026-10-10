<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use RuntimeException;

/**
 * Turns a token stream into a flat list of statements (labels, directives,
 * instructions). One statement per line; operands are split on top-level commas.
 */
final class Parser
{
    /** @param list<Token> $tokens @return list<Statement> */
    public function parse(array $tokens): array
    {
        $statements = [];
        $n = count($tokens);
        $i = 0;

        while ($i < $n) {
            $t = $tokens[$i];

            if ($t->type === Token::NEWLINE || $t->type === Token::EOF) {
                $i++;
                continue;
            }

            if ($t->type === Token::IDENT) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next->type === Token::PUNCT && $next->value === ':') {
                    $statements[] = new Statement(Statement::LABEL, $t->value, [], $t->line);
                    $i += 2;
                    continue;
                }
                if (str_starts_with($t->value, '.')) {
                    $name = substr($t->value, 1);
                    $i++;
                    $statements[] = new Statement(Statement::DIRECTIVE, $name, $this->collectOperands($tokens, $i, $n), $t->line);
                    continue;
                }
                $i++;
                $statements[] = new Statement(Statement::INSTRUCTION, $t->value, $this->collectOperands($tokens, $i, $n), $t->line);
                continue;
            }

            throw new RuntimeException("line {$t->line}: unexpected token '{$t->value}'");
        }

        return $statements;
    }

    /**
     * Collect operand token-lists until end of line, splitting on top-level commas.
     *
     * @param list<Token> $tokens
     * @return list<list<Token>>
     */
    private function collectOperands(array $tokens, int &$i, int $n): array
    {
        $operands = [];
        $current = [];
        $depth = 0;

        while ($i < $n) {
            $t = $tokens[$i];
            if ($t->type === Token::NEWLINE || $t->type === Token::EOF) {
                break;
            }
            if ($t->type === Token::PUNCT && $t->value === ',' && $depth === 0) {
                $operands[] = $current;
                $current = [];
                $i++;
                continue;
            }
            if ($t->type === Token::PUNCT && ($t->value === '[' || $t->value === '(')) {
                $depth++;
            }
            if ($t->type === Token::PUNCT && ($t->value === ']' || $t->value === ')')) {
                $depth--;
            }
            $current[] = $t;
            $i++;
        }

        if ($current !== []) {
            $operands[] = $current;
        }
        return $operands;
    }
}

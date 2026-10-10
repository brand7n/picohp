<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use PicoIR\Assembler\Output\RawBinary;
use RuntimeException;

/**
 * Two-pass AArch64 assembler.
 *
 * Pass 1: walk statements, assign each instruction a 4-byte address, record
 * labels. Pass 2: encode every instruction, resolving branch targets against
 * the label table. Output is raw little-endian machine code.
 */
final class Assembler
{
    public function assemble(string $source): string
    {
        $tokens = (new Lexer())->tokenize($source);
        $statements = (new Parser())->parse($tokens);

        // Pass 1: addresses and labels.
        $labels = [];
        $pc = 0;
        $instructions = [];

        foreach ($statements as $stmt) {
            if ($stmt->kind === Statement::LABEL) {
                if (isset($labels[$stmt->name])) {
                    throw new RuntimeException("line {$stmt->line}: duplicate label '{$stmt->name}'");
                }
                $labels[$stmt->name] = $pc;
            } elseif ($stmt->kind === Statement::INSTRUCTION) {
                $instructions[] = ['stmt' => $stmt, 'pc' => $pc];
                $pc += 4;
            } else {
                $this->checkDirective($stmt);
            }
        }

        // Pass 2: encode.
        $encoder = new InstructionEncoder();
        $words = [];
        foreach ($instructions as $ins) {
            $stmt = $ins['stmt'];
            $resolve = static function (string $name) use ($labels, $stmt): int {
                if (!isset($labels[$name])) {
                    throw new RuntimeException("line {$stmt->line}: undefined label '$name'");
                }
                return $labels[$name];
            };
            $words[] = $encoder->encode($stmt->name, $stmt->operands, $ins['pc'], $resolve);
        }

        return (new RawBinary())->encode($words);
    }

    private function checkDirective(Statement $stmt): void
    {
        $name = strtolower($stmt->name);
        if (in_array($name, ['text', 'global', 'globl'], true)) {
            return;
        }
        throw new RuntimeException("line {$stmt->line}: unsupported directive '.{$stmt->name}'");
    }
}

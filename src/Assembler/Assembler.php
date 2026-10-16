<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use PicoIR\Assembler\Output\{ELFObject, MachO, MachOObject, RawBinary};
use RuntimeException;

/**
 * Two-pass AArch64 assembler.
 *
 * Pass 1: walk statements, assign each instruction a 4-byte address, record
 * labels and global symbols. Pass 2: encode every instruction, resolving
 * branch targets against the label table.
 *
 * Output is either raw little-endian machine code (`assemble`) or a runnable
 * Mach-O arm64 executable (`assembleMachO`).
 */
final class Assembler
{
    public function assemble(string $source): string
    {
        [$words] = $this->assembleCore($source);
        return (new RawBinary())->encode($words);
    }

    public function assembleMachO(string $source): string
    {
        [$words, $labels, $globals] = $this->assembleCore($source);
        $entry = $this->entryLabel($labels, $globals);
        return (new MachO())->encode($words, $labels[$entry]);
    }

    public function assembleObject(string $source): string
    {
        [$words, $labels, $globals] = $this->assembleCore($source);
        $entry = $this->entryLabel($labels, $globals);
        return (new MachOObject())->encode($words, $entry, $labels[$entry]);
    }

    public function assembleElf(string $source): string
    {
        [$words, $labels, $globals] = $this->assembleCore($source);
        $entry = $this->entryLabel($labels, $globals);
        return (new ELFObject())->encode($words, $entry, $labels[$entry]);
    }

    /** @param array<string, int> $labels @param list<string> $globals */
    private function entryLabel(array $labels, array $globals): string
    {
        if (isset($labels['_main'])) {
            return '_main';
        }
        foreach ($globals as $name) {
            if (isset($labels[$name])) {
                return $name;
            }
        }
        throw new RuntimeException('no global entry label (e.g. .globl _main) found');
    }

    /**
     * @return array{list<int>, array<string, int>, list<string>}
     */
    private function assembleCore(string $source): array
    {
        $tokens = (new Lexer())->tokenize($source);
        $statements = (new Parser())->parse($tokens);

        $labels = [];
        $globals = [];
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
                $this->checkDirective($stmt, $globals);
            }
        }

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

        return [$words, $labels, $globals];
    }

    /** @param list<string> $globals */
    private function checkDirective(Statement $stmt, array &$globals): void
    {
        $name = strtolower($stmt->name);
        if (in_array($name, ['text', 'section', 'p2align', 'align'], true)) {
            return;
        }
        if ($name === 'global' || $name === 'globl') {
            foreach ($stmt->operands as $op) {
                if (count($op) === 1 && $op[0]->type === Token::IDENT) {
                    $globals[] = $op[0]->value;
                }
            }
            return;
        }
        throw new RuntimeException("line {$stmt->line}: unsupported directive '.{$stmt->name}'");
    }
}

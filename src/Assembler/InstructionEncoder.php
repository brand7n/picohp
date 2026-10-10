<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use RuntimeException;

/**
 * Encodes a parsed instruction (mnemonic + operand token-lists) into a single
 * 32-bit AArch64 instruction word. Branch instructions resolve their target
 * through a caller-supplied label resolver.
 */
final class InstructionEncoder
{
    /**
     * @param list<list<Token>> $operands
     * @param callable(string): int $resolveLabel label name => target address
     */
    public function encode(string $mnemonic, array $operands, int $pc, callable $resolveLabel): int
    {
        $m = strtolower($mnemonic);
        $err = static function (string $msg) use ($m): never {
            throw new RuntimeException("$m: $msg");
        };

        if ($m === 'ret') {
            if ($operands === []) {
                return 0xD65F03C0; // RET X30
            }
            if (count($operands) === 1) {
                return 0xD65F0000 | ($this->reg($operands[0], true, $m) << 5);
            }
            $err('RET accepts zero operands or one register');
        }

        if ($m === 'b') {
            if (count($operands) !== 1) {
                $err('B expects one label');
            }
            $words = $this->branchWords($operands[0], $pc, $resolveLabel, $m, 26, $err);
            return 0x14000000 | ($words & 0x03FFFFFF);
        }

        if ($m === 'cbz' || $m === 'cbnz') {
            if (count($operands) !== 2) {
                $err(strtoupper($m) . ' expects a register and a label');
            }
            $rt = $this->reg($operands[0], true, $m);
            $words = $this->branchWords($operands[1], $pc, $resolveLabel, $m, 19, $err);
            $base = $m === 'cbz' ? 0xB4000000 : 0xB5000000;
            return $base | (($words & 0x7FFFF) << 5) | $rt;
        }

        $rr = [
            'add' => [0x8B000000, 0x0B000000],
            'sub' => [0xCB000000, 0x4B000000],
            'adds' => [0xAB000000, 0x2B000000],
            'subs' => [0xEB000000, 0x6B000000],
            'and' => [0x8A000000, 0x0A000000],
            'orr' => [0xAA000000, 0x2A000000],
            'eor' => [0xCA000000, 0x4A000000],
        ];
        if (isset($rr[$m])) {
            if (count($operands) !== 3) {
                $err(strtoupper($m) . ' expects three operands');
            }
            [$rd, $rn, $rm] = $operands;
            $x = $this->isX($rd);
            if ($this->isX($rn) !== $x || $this->isX($rm) !== $x) {
                $err('register width mismatch');
            }
            $base = $x ? $rr[$m][0] : $rr[$m][1];
            return $base
                | ($this->reg($rm, $x, $m) << 16)
                | ($this->reg($rn, $x, $m) << 5)
                | $this->reg($rd, $x, $m);
        }

        if ($m === 'cmp') {
            if (count($operands) !== 2) {
                $err('CMP expects two operands');
            }
            [$rn, $rm] = $operands;
            $x = $this->isX($rn);
            if ($this->isX($rm) !== $x) {
                $err('register width mismatch');
            }
            $base = $x ? 0xEB00001F : 0x6B00001F; // SUBS xzr/wzr, Xn, Xm
            return $base | ($this->reg($rm, $x, $m) << 16) | ($this->reg($rn, $x, $m) << 5);
        }

        $mv = [
            'movz' => [0xD2800000, 0x52800000],
            'movk' => [0xF2800000, 0x72800000],
            'movn' => [0x92800000, 0x12800000],
        ];
        if (isset($mv[$m])) {
            if (count($operands) !== 2) {
                $err(strtoupper($m) . ' expects a register and an immediate');
            }
            $rd = $operands[0];
            $x = $this->isX($rd);
            $imm = $this->imm($operands[1], $m);
            if ($imm < 0 || $imm > 0xFFFF) {
                $err('immediate must fit in 16 bits');
            }
            $base = $x ? $mv[$m][0] : $mv[$m][1];
            return $base | ($imm << 5) | $this->reg($rd, $x, $m);
        }

        throw new RuntimeException("unsupported instruction '$m'");
    }

    /**
     * @param list<Token> $operand
     * @param callable(string): int $resolveLabel
     * @param callable(string): never $err
     */
    private function branchWords(array $operand, int $pc, callable $resolveLabel, string $m, int $bits, callable $err): int
    {
        if (count($operand) !== 1 || $operand[0]->type !== Token::IDENT) {
            $err('expected a label');
        }
        $target = $resolveLabel($operand[0]->value);
        $offset = $target - $pc;
        if ($offset % 4 !== 0) {
            $err('branch target must be 4-byte aligned');
        }
        $words = intdiv($offset, 4);
        $limit = 1 << ($bits - 1);
        if ($words < -$limit || $words >= $limit) {
            $err('branch target out of range');
        }
        return $words;
    }

    /** @param list<Token> $tokens */
    private function reg(array $tokens, bool $x, string $m): int
    {
        if (count($tokens) !== 1 || $tokens[0]->type !== Token::IDENT) {
            throw new RuntimeException("$m: expected a register");
        }
        $name = strtolower($tokens[0]->value);
        if ($x) {
            if ($name === 'xzr' || $name === 'sp') {
                return 31;
            }
            if (preg_match('/^x([0-9]|[12][0-9]|30)$/', $name, $mm)) {
                return (int) $mm[1];
            }
        } else {
            if ($name === 'wzr' || $name === 'wsp') {
                return 31;
            }
            if (preg_match('/^w([0-9]|[12][0-9]|30)$/', $name, $mm)) {
                return (int) $mm[1];
            }
        }
        throw new RuntimeException("$m: expected a " . ($x ? 'X' : 'W') . " register, got '$name'");
    }

    /** @param list<Token> $tokens */
    private function imm(array $tokens, string $m): int
    {
        if (count($tokens) >= 2 && $tokens[0]->type === Token::PUNCT && $tokens[0]->value === '#') {
            $tokens = array_slice($tokens, 1);
        }
        if (count($tokens) !== 1 || $tokens[0]->type !== Token::NUMBER) {
            throw new RuntimeException("$m: expected an immediate");
        }
        $v = $tokens[0]->value;
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $v)) {
            return (int) hexdec(substr($v, 2));
        }
        if (preg_match('/^[0-9]+$/', $v)) {
            return (int) $v;
        }
        throw new RuntimeException("$m: invalid immediate '$v'");
    }

    /** @param list<Token> $tokens */
    private function isX(array $tokens): bool
    {
        if (count($tokens) === 1 && $tokens[0]->type === Token::IDENT) {
            $name = strtolower($tokens[0]->value);
            if (preg_match('/^x/', $name) || $name === 'xzr' || $name === 'sp') {
                return true;
            }
            if (preg_match('/^w/', $name) || $name === 'wzr' || $name === 'wsp') {
                return false;
            }
        }
        return true;
    }
}

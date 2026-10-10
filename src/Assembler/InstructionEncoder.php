<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use RuntimeException;

/**
 * Encodes a parsed instruction (mnemonic + operand token-lists) into a single
 * 32-bit AArch64 instruction word. Branch instructions resolve their target
 * through a caller-supplied label resolver.
 *
 * Encodings verified against Apple's `clang -arch arm64` (see tests).
 */
final class InstructionEncoder
{
    private const COND = [
        'eq' => 0, 'ne' => 1, 'cs' => 2, 'hs' => 2, 'cc' => 3, 'lo' => 3,
        'mi' => 4, 'pl' => 5, 'vs' => 6, 'vc' => 7, 'hi' => 8, 'ls' => 9,
        'ge' => 10, 'lt' => 11, 'gt' => 12, 'le' => 13, 'al' => 14,
    ];

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

        if ($m === 'b' || $m === 'bl') {
            if (count($operands) !== 1) {
                $err(strtoupper($m) . ' expects one label');
            }
            $words = $this->branchWords($operands[0], $pc, $resolveLabel, $m, 26, $err);
            $base = $m === 'b' ? 0x14000000 : 0x94000000;
            return $base | ($words & 0x03FFFFFF);
        }

        if (str_starts_with($m, 'b.')) {
            $cond = $this->cond(substr($m, 2), $err);
            if (count($operands) !== 1) {
                $err('B.cond expects one label');
            }
            $words = $this->branchWords($operands[0], $pc, $resolveLabel, $m, 19, $err);
            return 0x54000000 | (($words & 0x7FFFF) << 5) | $cond;
        }

        if ($m === 'cbz' || $m === 'cbnz') {
            if (count($operands) !== 2) {
                $err(strtoupper($m) . ' expects a register and a label');
            }
            $rt = $operands[0];
            $x = $this->isX($rt);
            $rtn = $this->reg($rt, $x, $m);
            $words = $this->branchWords($operands[1], $pc, $resolveLabel, $m, 19, $err);
            $base = match ([$m, $x]) {
                ['cbz', true] => 0xB4000000, ['cbz', false] => 0x34000000,
                ['cbnz', true] => 0xB5000000, ['cbnz', false] => 0x35000000,
            };
            return $base | (($words & 0x7FFFF) << 5) | $rtn;
        }

        if ($m === 'cset') {
            if (count($operands) !== 2) {
                $err('CSET expects a register and a condition');
            }
            $rd = $operands[0];
            $x = $this->isX($rd);
            $cond = $this->cond($this->ident($operands[1], $m), $err);
            // CSET = CSINC Xd, XZR, XZR, NOT(cond); CSINC base carries the 0x400 op bit
            $base = $x ? 0x9A800400 : 0x1A800400;
            $inv = $cond ^ 1;
            return $base | (31 << 16) | ($inv << 12) | (31 << 5) | $this->reg($rd, $x, $m);
        }

        $rr = [
            'and' => [0x8A000000, 0x0A000000],
            'orr' => [0xAA000000, 0x2A000000],
            'eor' => [0xCA000000, 0x4A000000],
            'mul' => [0x9B007C00, 0x1B007C00],
            'sdiv' => [0x9AC00C00, 0x1AC00C00],
            'lsl' => [0x9AC02000, 0x1AC02000],
            'lsr' => [0x9AC02400, 0x1AC02400],
            'asr' => [0x9AC02800, 0x1AC02800],
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

        if ($m === 'msub') {
            if (count($operands) !== 4) {
                $err('MSUB expects four operands');
            }
            [$rd, $rn, $rm, $ra] = $operands;
            $x = $this->isX($rd);
            $base = $x ? 0x9B008000 : 0x1B008000;
            return $base
                | ($this->reg($rm, $x, $m) << 16)
                | ($this->reg($ra, $x, $m) << 10)
                | ($this->reg($rn, $x, $m) << 5)
                | $this->reg($rd, $x, $m);
        }

        if ($m === 'add' || $m === 'sub' || $m === 'adds' || $m === 'subs' || $m === 'cmp') {
            if ($m === 'cmp') {
                if (count($operands) !== 2) {
                    $err('CMP expects two operands');
                }
                [$rn, $rm] = $operands;
                $rd = 31;
            } elseif (count($operands) === 2) {
                [$rn, $rm] = $operands;
                $rd = $rn; // alias: add Xn, Xm == add Xn, Xn, Xm
            } elseif (count($operands) === 3) {
                [$rd, $rn, $rm] = $operands;
            } else {
                $err(strtoupper($m) . ' expects two or three operands');
            }
            $x = $this->isX($rn);
            if (!$this->isImmediate($rm) && $this->isX($rm) !== $x) {
                $err('register width mismatch');
            }
            if ($m !== 'cmp' && $this->isX($rd) !== $x) {
                $err('register width mismatch');
            }
            $rdn = $m === 'cmp' ? 31 : $this->reg($rd, $x, $m);
            if ($this->isImmediate($rm)) {
                $imm = $this->imm($rm, $m);
                if ($imm < 0 || $imm > 0xFFF) {
                    $err('immediate must fit in 12 bits');
                }
                $base = match ($m) {
                    'add' => $x ? 0x91000000 : 0x11000000,
                    'sub' => $x ? 0xD1000000 : 0x51000000,
                    'adds' => $x ? 0xB1000000 : 0x31000000,
                    'subs', 'cmp' => $x ? 0xF1000000 : 0x71000000,
                };
                return $base | ($imm << 10) | ($this->reg($rn, $x, $m) << 5) | $rdn;
            }
            $base = match ($m) {
                'add' => $x ? 0x8B000000 : 0x0B000000,
                'sub' => $x ? 0xCB000000 : 0x4B000000,
                'adds' => $x ? 0xAB000000 : 0x2B000000,
                'subs', 'cmp' => $x ? 0xEB000000 : 0x6B000000,
            };
            return $base | ($this->reg($rm, $x, $m) << 16) | ($this->reg($rn, $x, $m) << 5) | $rdn;
        }

        if ($m === 'mov') {
            if (count($operands) !== 2) {
                $err('MOV expects two operands');
            }
            [$rd, $rm] = $operands;
            $x = $this->isX($rd);
            if ($this->isImmediate($rm)) {
                $imm = $this->imm($rm, $m);
                if ($imm >= 0 && $imm <= 0xFFFF) {
                    $base = $x ? 0xD2800000 : 0x52800000;
                    return $base | ($imm << 5) | $this->reg($rd, $x, $m);
                }
                if ($imm < 0 && $imm >= -0x10000) {
                    $base = $x ? 0x92800000 : 0x12800000;
                    return $base | ((~$imm & 0xFFFF) << 5) | $this->reg($rd, $x, $m);
                }
                $err('immediate must fit in 16 bits');
            }
            // mov reg, reg: add #0 if sp involved, else orr with xzr
            $rn = $this->reg($rm, $x, $m);
            $rdn = $this->reg($rd, $x, $m);
            if ($rn === 31 || $rdn === 31) {
                $base = $x ? 0x91000000 : 0x11000000;
                return $base | ($rn << 5) | $rdn;
            }
            // mov Xd, Xn == orr Xd, XZR, Xn (matches clang's encoding)
            $base = $x ? 0xAA000000 : 0x2A000000;
            return $base | ($rn << 16) | (31 << 5) | $rdn;
        }

        $mv = [
            'movz' => [0xD2800000, 0x52800000],
            'movk' => [0xF2800000, 0x72800000],
            'movn' => [0x92800000, 0x12800000],
        ];
        if (isset($mv[$m])) {
            if (count($operands) < 2 || count($operands) > 3) {
                $err(strtoupper($m) . ' expects a register, immediate, and optional shift');
            }
            $rd = $operands[0];
            $x = $this->isX($rd);
            $imm = $this->imm($operands[1], $m);
            if ($imm < 0 || $imm > 0xFFFF) {
                $err('immediate must fit in 16 bits');
            }
            $hw = 0;
            if (count($operands) === 3) {
                $shift = $this->shift($operands[2], $m);
                if ($shift % 16 !== 0 || $shift > 48) {
                    $err('shift must be a multiple of 16 up to 48');
                }
                $hw = intdiv($shift, 16);
            }
            $base = $x ? $mv[$m][0] : $mv[$m][1];
            return $base | ($hw << 21) | ($imm << 5) | $this->reg($rd, $x, $m);
        }

        if ($m === 'str' || $m === 'ldr') {
            if (count($operands) === 3 && $this->isImmediate($operands[2])) {
                $addr = $this->postIndex($operands[1], $operands[2]);
            } elseif (count($operands) === 2) {
                $addr = $operands[1];
            } else {
                $err(strtoupper($m) . ' expects a register and an address');
            }
            $rt = $operands[0];
            $x = $this->isX($rt);
            [$rn, $offset, $mode] = $this->mem($addr, $m);
            $scale = $x ? 8 : 4;
            $rtn = $this->reg($rt, $x, $m);
            $rnn = $rn;
            $isLdr = $m === 'ldr';
            if ($mode === 'offset') {
                if ($offset === 0) {
                    $base = $x ? 0xF9400000 : 0xB9400000;
                    return $base | ($rnn << 5) | $rtn;
                }
                if ($offset > 0 && $offset % $scale === 0 && intdiv($offset, $scale) <= 0xFFF) {
                    $base = $x ? 0xF9000000 : 0xB9000000;
                    return $base | (intdiv($offset, $scale) << 10) | ($rnn << 5) | $rtn;
                }
                // STUR/LDUR unscaled
                $base = $x ? ($isLdr ? 0xF8400000 : 0xF8000000) : ($isLdr ? 0xB8400000 : 0xB8000000);
                return $base | (($offset & 0x1FF) << 12) | ($rnn << 5) | $rtn;
            }
            if ($mode === 'pre') {
                $base = $x ? ($isLdr ? 0xF8400C00 : 0xF8000C00) : ($isLdr ? 0xB8400C00 : 0xB8000C00);
                return $base | (($offset & 0x1FF) << 12) | ($rnn << 5) | $rtn;
            }
            $base = $x ? ($isLdr ? 0xF8400400 : 0xF8000400) : ($isLdr ? 0xB8400400 : 0xB8000400);
            return $base | (($offset & 0x1FF) << 12) | ($rnn << 5) | $rtn;
        }

        if ($m === 'stp' || $m === 'ldp') {
            if (count($operands) === 4 && $this->isImmediate($operands[3])) {
                $addr = $this->postIndex($operands[2], $operands[3]);
            } elseif (count($operands) === 3) {
                $addr = $operands[2];
            } else {
                $err(strtoupper($m) . ' expects two registers and an address');
            }
            $rt = $operands[0];
            $rt2 = $operands[1];
            $x = $this->isX($rt);
            if ($this->isX($rt2) !== $x) {
                $err('register width mismatch');
            }
            [$rn, $offset, $mode] = $this->mem($addr, $m);
            $scale = $x ? 8 : 4;
            if ($offset % $scale !== 0) {
                $err('pair offset must be a multiple of ' . $scale);
            }
            $imm7 = intdiv($offset, $scale) & 0x7F;
            $rtn = $this->reg($rt, $x, $m);
            $rt2n = $this->reg($rt2, $x, $m);
            $rnn = $rn;
            $base = match ([$m, $mode]) {
                ['stp', 'offset'] => $x ? 0xA9000000 : 0x29000000,
                ['stp', 'pre'] => $x ? 0xA9800000 : 0x29800000,
                ['stp', 'post'] => $x ? 0xA8800000 : 0x28800000,
                ['ldp', 'offset'] => $x ? 0xA9400000 : 0x29400000,
                ['ldp', 'pre'] => $x ? 0xA9C00000 : 0x29C00000,
                ['ldp', 'post'] => $x ? 0xA8C00000 : 0x28C00000,
            };
            return $base | ($imm7 << 15) | ($rt2n << 10) | ($rnn << 5) | $rtn;
        }

        if ($m === 'brk') {
            if (count($operands) !== 1) {
                $err('BRK expects an immediate');
            }
            $imm = $this->imm($operands[0], $m);
            return 0xD4200000 | (($imm & 0xFFFF) << 5);
        }

        $fbin = [
            'fadd' => 0x1E602800, 'fsub' => 0x1E603800,
            'fmul' => 0x1E600800, 'fdiv' => 0x1E601800,
        ];
        if (isset($fbin[$m])) {
            if (count($operands) !== 3) {
                $err(strtoupper($m) . ' expects three operands');
            }
            [$rd, $rn, $rm] = $operands;
            return $fbin[$m]
                | ($this->freg($rm, $m) << 16)
                | ($this->freg($rn, $m) << 5)
                | $this->freg($rd, $m);
        }

        if ($m === 'fcmp') {
            if (count($operands) !== 2) {
                $err('FCMP expects two operands');
            }
            [$rn, $rm] = $operands;
            return 0x1E602000
                | ($this->freg($rm, $m) << 16)
                | ($this->freg($rn, $m) << 5);
        }

        if ($m === 'fcsel') {
            if (count($operands) !== 4) {
                $err('FCSEL expects three registers and a condition');
            }
            [$rd, $rn, $rm, $cond] = $operands;
            return 0x1E600C00
                | ($this->freg($rm, $m) << 16)
                | ($this->cond($this->ident($cond, $m), $err) << 12)
                | ($this->freg($rn, $m) << 5)
                | $this->freg($rd, $m);
        }

        if ($m === 'fmov') {
            if (count($operands) !== 2) {
                $err('FMOV expects two operands');
            }
            [$rd, $rn] = $operands;
            $rdn = $this->freg($rd, $m);
            $rnn = $this->reg($rn, true, $m);
            return 0x9E670000 | ($rnn << 5) | $rdn;
        }

        if ($m === 'scvtf') {
            if (count($operands) !== 2) {
                $err('SCVTF expects two operands');
            }
            [$rd, $rn] = $operands;
            return 0x1E620000 | ($this->reg($rn, false, $m) << 5) | $this->freg($rd, $m);
        }

        if ($m === 'fcvtzs') {
            if (count($operands) !== 2) {
                $err('FCVTZS expects two operands');
            }
            [$rd, $rn] = $operands;
            return 0x1E780000 | ($this->freg($rn, $m) << 5) | $this->reg($rd, false, $m);
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

    /**
     * Parse a memory operand `[Rn]`, `[Rn, #imm]`, `[Rn, #imm]!`, or `[Rn], #imm`.
     *
     * @param list<Token> $tokens
     * @return array{int, int, string} [rn, offset, mode]
     */
    private function mem(array $tokens, string $m): array
    {
        $n = count($tokens);
        if ($n < 3 || $tokens[0]->type !== Token::PUNCT || $tokens[0]->value !== '[') {
            throw new RuntimeException("$m: expected a memory operand");
        }
        $rn = $this->reg([$tokens[1]], true, $m);
        $close = -1;
        for ($i = 2; $i < $n; $i++) {
            if ($tokens[$i]->type === Token::PUNCT && $tokens[$i]->value === ']') {
                $close = $i;
                break;
            }
        }
        if ($close < 0) {
            throw new RuntimeException("$m: malformed memory operand");
        }
        $offset = 0;
        $mode = 'offset';
        if ($close > 2) {
            if ($tokens[2]->type !== Token::PUNCT || $tokens[2]->value !== ',') {
                throw new RuntimeException("$m: malformed memory operand");
            }
            $offset = $this->imm(array_slice($tokens, 3, $close - 3), $m);
        }
        $i = $close + 1;
        if ($i < $n && $tokens[$i]->type === Token::PUNCT && $tokens[$i]->value === '!') {
            $mode = 'pre';
            $i++;
        } elseif ($i < $n && $tokens[$i]->type === Token::PUNCT && $tokens[$i]->value === ',') {
            $mode = 'post';
            $offset = $this->imm(array_slice($tokens, $i + 1), $m);
            $i = $n;
        }
        if ($i !== $n) {
            throw new RuntimeException("$m: trailing tokens in memory operand");
        }
        return [$rn, $offset, $mode];
    }

    /**
     * Rebuild a post-index memory operand `[Rn], #imm` from the two operands
     * the parser split on the top-level comma.
     *
     * @param list<Token> $addr
     * @param list<Token> $imm
     * @return list<Token>
     */
    private function postIndex(array $addr, array $imm): array
    {
        return array_merge($addr, [new Token(Token::PUNCT, ',', 0)], $imm);
    }

    /** @param list<Token> $tokens */
    private function shift(array $tokens, string $m): int
    {
        if (count($tokens) >= 2 && $tokens[0]->type === Token::IDENT && strtolower($tokens[0]->value) === 'lsl') {
            return $this->imm(array_slice($tokens, 1), $m);
        }
        throw new RuntimeException("$m: expected 'lsl #shift'");
    }

    /** @param list<Token> $tokens */
    private function ident(array $tokens, string $m): string
    {
        if (count($tokens) !== 1 || $tokens[0]->type !== Token::IDENT) {
            throw new RuntimeException("$m: expected an identifier");
        }
        return strtolower($tokens[0]->value);
    }

    private function cond(string $name, callable $err): int
    {
        if (!isset(self::COND[$name])) {
            $err("unknown condition '$name'");
        }
        return self::COND[$name];
    }

    /** @param list<Token> $tokens */
    private function isImmediate(array $tokens): bool
    {
        if (count($tokens) >= 1 && $tokens[0]->type === Token::PUNCT && $tokens[0]->value === '#') {
            return true;
        }
        return count($tokens) === 1 && $tokens[0]->type === Token::NUMBER;
    }

    /** @param list<Token> $tokens */
    private function freg(array $tokens, string $m): int
    {
        if (count($tokens) !== 1 || $tokens[0]->type !== Token::IDENT) {
            throw new RuntimeException("$m: expected a float register");
        }
        $name = strtolower($tokens[0]->value);
        if (preg_match('/^d([0-9]|[12][0-9]|30|31)$/', $name, $mm)) {
            return (int) $mm[1];
        }
        throw new RuntimeException("$m: expected a D register, got '$name'");
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
        if (count($tokens) >= 1 && $tokens[0]->type === Token::PUNCT && $tokens[0]->value === '#') {
            $tokens = array_slice($tokens, 1);
        }
        $neg = false;
        if (count($tokens) >= 1 && $tokens[0]->type === Token::PUNCT && ($tokens[0]->value === '-' || $tokens[0]->value === '+')) {
            $neg = $tokens[0]->value === '-';
            $tokens = array_slice($tokens, 1);
        }
        if (count($tokens) !== 1 || $tokens[0]->type !== Token::NUMBER) {
            throw new RuntimeException("$m: expected an immediate");
        }
        $v = $tokens[0]->value;
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $v)) {
            $val = (int) hexdec(substr($v, 2));
        } elseif (preg_match('/^[0-9]+$/', $v)) {
            $val = (int) $v;
        } else {
            throw new RuntimeException("$m: invalid immediate '$v'");
        }
        return $neg ? -$val : $val;
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

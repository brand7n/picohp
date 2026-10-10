<?php

declare(strict_types=1);

use PicoIR\Assembler\Assembler;

function asm(string $source): string
{
    return (new Assembler())->assemble($source);
}

function words(string $binary): array
{
    return array_map('unpack', array_fill(0, intdiv(strlen($binary), 4), 'V'));
}

it('encodes ADD Xd, Xn, Xm', function () {
    expect(bin2hex(asm("add x0, x1, x2")))->toBe('2000028b');
});

it('encodes SUB Xd, Xn, Xm', function () {
    expect(bin2hex(asm("sub x3, x4, x5")))->toBe('830005cb');
});

it('encodes MOVZ Xd, #imm', function () {
    expect(bin2hex(asm("movz x0, #42")))->toBe('400580d2');
});

it('encodes W-register arithmetic', function () {
    expect(bin2hex(asm("add w0, w1, w2")))->toBe('2000020b');
});

it('encodes MOVK and MOVN', function () {
    expect(bin2hex(asm("movk x0, #0x1234")))->toBe('804682f2');
    expect(bin2hex(asm("movn x1, #1")))->toBe('21008092');
});

it('encodes CMP as SUBS xzr', function () {
    expect(bin2hex(asm("cmp x0, x1")))->toBe('1f0001eb');
});

it('encodes RET with no operand and with a register', function () {
    expect(bin2hex(asm("ret")))->toBe('c0035fd6');
    expect(bin2hex(asm("ret x0")))->toBe('00005fd6');
});

it('resolves a backward branch', function () {
    // loop: sub x0,x0,x1 ; b loop  => b offset -4 (in words -1)
    $bin = asm("loop:\nsub x0, x0, x1\nb loop");
    expect(bin2hex(substr($bin, 4)))->toBe('ffffff17');
});

it('resolves a forward branch', function () {
    // b done ; nop-ish ; done: ret  => b offset +8 (in words +2)
    $bin = asm("b done\nmovz x0, #0\ndone:\nret");
    expect(bin2hex(substr($bin, 0, 4)))->toBe('02000014');
});

it('encodes CBZ and CBNZ', function () {
    $bin = asm("cbz x0, done\ncbnz x1, done\ndone:\nret");
    expect(bin2hex(substr($bin, 0, 4)))->toBe('400000b4');
    expect(bin2hex(substr($bin, 4, 4)))->toBe('210000b5');
});

it('emits 20 bytes for the hello example', function () {
    $src = file_get_contents(dirname(__DIR__, 2) . '/examples/hello.s');
    expect(strlen(asm($src)))->toBe(20);
});

it('rejects a duplicate label', function () {
    asm("a:\nret\na:\nret");
})->throws(RuntimeException::class, 'duplicate label');

it('rejects an undefined label', function () {
    asm("b nowhere");
})->throws(RuntimeException::class, 'undefined label');

it('rejects an unsupported directive', function () {
    asm(".data\nret");
})->throws(RuntimeException::class, 'unsupported directive');

it('rejects an unsupported instruction', function () {
    asm("adrp x0, _foo");
})->throws(RuntimeException::class, 'unsupported instruction');

it('rejects an out-of-range immediate', function () {
    asm("movz x0, #65536");
})->throws(RuntimeException::class, 'immediate must fit in 16 bits');

it('encodes MOV reg, #imm and MOV reg, reg', function () {
    expect(bin2hex(asm("mov x0, #15")))->toBe('e00180d2');
    expect(bin2hex(asm("mov x0, #-5")))->toBe('80008092');
    expect(bin2hex(asm("mov x2, x3")))->toBe('e20303aa');
    expect(bin2hex(asm("mov x29, sp")))->toBe('fd030091');
});

it('encodes ADD/SUB immediate', function () {
    expect(bin2hex(asm("sub sp, sp, #48")))->toBe('ffc300d1');
    expect(bin2hex(asm("add sp, sp, #48")))->toBe('ffc30091');
    expect(bin2hex(asm("sub x4, x5, #8")))->toBe('a42000d1');
});

it('encodes CMP immediate', function () {
    expect(bin2hex(asm("cmp w0, #5")))->toBe('1f140071');
});

it('encodes CSET', function () {
    expect(bin2hex(asm("cset w0, lt")))->toBe('e0a79f1a');
    expect(bin2hex(asm("cset x0, eq")))->toBe('e0179f9a');
});

it('encodes MUL, SDIV, MSUB, LSL, ASR', function () {
    expect(bin2hex(asm("mul w0, w0, w1")))->toBe('007c011b');
    expect(bin2hex(asm("sdiv x0, x1, x2")))->toBe('200cc29a');
    expect(bin2hex(asm("msub x0, x1, x2, x3")))->toBe('208c029b');
    expect(bin2hex(asm("lsl x0, x1, x2")))->toBe('2020c29a');
    expect(bin2hex(asm("asr x0, x1, x2")))->toBe('2028c29a');
});

it('encodes MOVZ/MOVK with shift', function () {
    expect(bin2hex(asm("movz x0, #0x1234, lsl #16")))->toBe('8046a2d2');
    expect(bin2hex(asm("movk x0, #0x5678, lsl #32")))->toBe('00cfcaf2');
});

it('encodes STR/LDR with negative offset as STUR/LDUR', function () {
    expect(bin2hex(asm("str x0, [x29, #-24]")))->toBe('a0831ef8');
    expect(bin2hex(asm("ldr x0, [x29, #-24]")))->toBe('a0835ef8');
    expect(bin2hex(asm("ldr x0, [x29]")))->toBe('a00340f9');
    expect(bin2hex(asm("str w0, [x29, #4]")))->toBe('a00700b9');
});

it('encodes STP pre-index and LDP post-index', function () {
    expect(bin2hex(asm("stp x29, x30, [sp, #-16]!")))->toBe('fd7bbfa9');
    expect(bin2hex(asm("ldp x29, x30, [sp], #16")))->toBe('fd7bc1a8');
});

it('encodes conditional branches', function () {
    $bin = asm("b.eq done\nb.ne done\ndone:\nret");
    expect(bin2hex(substr($bin, 0, 4)))->toBe('40000054');
    expect(bin2hex(substr($bin, 4, 4)))->toBe('21000054');
});

it('encodes BL to a local label', function () {
    $bin = asm("bl _main\n_main:\nret");
    expect(bin2hex(substr($bin, 0, 4)))->toBe('01000094');
});

it('encodes CBNZ with a W register', function () {
    $bin = asm("cbnz w0, done\ndone:\nret");
    expect(bin2hex(substr($bin, 0, 4)))->toBe('20000035');
});

it('encodes floating-point instructions', function () {
    expect(bin2hex(asm("fmov d0, xzr")))->toBe('e003679e');
    expect(bin2hex(asm("fadd d0, d0, d1")))->toBe('0028611e');
    expect(bin2hex(asm("fcmp d0, d1")))->toBe('0020611e');
    expect(bin2hex(asm("scvtf d0, w0")))->toBe('0000621e');
    expect(bin2hex(asm("fcvtzs w0, d0")))->toBe('0000781e');
});

it('produces a linkable Mach-O object with _main at its address', function () {
    $src = ".text\n.globl _main\n_main:\nstp x29, x30, [sp, #-16]!\nmov x29, sp\nsub sp, sp, #16\nmov x0, #42\nadd sp, sp, #16\nldp x29, x30, [sp], #16\nret";
    $obj = (new Assembler())->assembleObject($src);
    $tmp = sys_get_temp_dir() . '/picoir_test_' . uniqid();
    file_put_contents("$tmp.o", $obj);
    exec("clang -arch arm64 -o $tmp $tmp.o 2>&1", $o, $code);
    expect($code)->toBe(0, implode("\n", $o));
    exec("$tmp", $o, $code);
    expect($code)->toBe(42);
    @unlink("$tmp.o");
    @unlink($tmp);
});

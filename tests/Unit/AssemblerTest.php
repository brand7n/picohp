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
    asm("ldr x0, [x1]");
})->throws(RuntimeException::class, 'unsupported instruction');

it('rejects an out-of-range immediate', function () {
    asm("movz x0, #65536");
})->throws(RuntimeException::class, 'immediate must fit in 16 bits');

it('rejects a register width mismatch', function () {
    asm("add x0, w1, x2");
})->throws(RuntimeException::class, 'register width mismatch');

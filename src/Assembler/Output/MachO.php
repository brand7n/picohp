<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

use Remodulate\Pico\PicoString;
use Remodulate\Pico\StringBuilder;

/**
 * Serializes instruction words into a minimal runnable Mach-O arm64
 * executable (MH_EXECUTE, PIE). Layout:
 *
 *   mach_header_64
 *   LC_SEGMENT_64 __TEXT (one __text section)
 *   LC_MAIN (entry point)
 *   __text code
 *
 * No __DATA, no __LINKEDIT, no relocations — sufficient for self-contained
 * programs (no libc / no globals).
 */
final class MachO
{
    private const MH_MAGIC_64 = 0xFEEDFACF;
    private const CPU_TYPE_ARM64 = 0x0100000C;
    private const MH_EXECUTE = 0x2;
    private const MH_PIE = 0x200000;
    private const MH_TWOLEVEL = 0x80;
    private const MH_DYLDLINK = 0x4;
    private const MH_NOUNDEFS = 0x1;
    private const LC_SEGMENT_64 = 0x19;
    private const LC_MAIN = 0x80000028;
    private const VM_BASE = 0x100000000;
    private const PAGE = 0x4000;

    /**
     * @param list<int> $words
     */
    public function encode(array $words, int $entry): PicoString
    {
        $code = new StringBuilder();
        foreach ($words as $word) {
            $code->appendU32LE($word);
        }
        $codeSize = $code->length();

        $textOffset = 32 + 152 + 24; // header + segment + LC_MAIN
        $textAddr = self::VM_BASE;
        $vmsize = intdiv($codeSize + self::PAGE - 1, self::PAGE) * self::PAGE;
        $entryoff = $textOffset + $entry;

        $flags = self::MH_PIE | self::MH_TWOLEVEL | self::MH_DYLDLINK | self::MH_NOUNDEFS;

        $out = new StringBuilder();
        // mach_header_64
        $out->appendU32LE(self::MH_MAGIC_64);
        $out->appendU32LE(self::CPU_TYPE_ARM64);
        $out->appendU32LE(0); // cpusubtype
        $out->appendU32LE(self::MH_EXECUTE);
        $out->appendU32LE(2); // ncmds
        $out->appendU32LE(176); // sizeofcmds
        $out->appendU32LE($flags);
        $out->appendU32LE(0); // reserved

        // LC_SEGMENT_64 __TEXT
        $out->appendU32LE(self::LC_SEGMENT_64);
        $out->appendU32LE(152); // cmdsize
        $out->append($this->cstr('__TEXT', 16));
        $out->appendU64LE($textAddr); // vmaddr
        $out->appendU64LE($vmsize); // vmsize
        $out->appendU64LE(0); // fileoff
        $out->appendU64LE($codeSize); // filesize
        $out->appendU32LE(7); // maxprot
        $out->appendU32LE(5); // initprot (r-x)
        $out->appendU32LE(1); // nsects
        $out->appendU32LE(0); // flags

        // section __text
        $out->append($this->cstr('__text', 16));
        $out->append($this->cstr('__TEXT', 16));
        $out->appendU64LE($textAddr); // addr
        $out->appendU64LE($codeSize); // size
        $out->appendU32LE($textOffset); // offset
        $out->appendU32LE(2); // align (2^2 = 4)
        $out->appendU32LE(0); // reloff
        $out->appendU32LE(0); // nreloc
        $out->appendU32LE(0x80000400); // flags: S_ATTR_PURE_INSTRUCTIONS | S_REGULAR
        $out->appendU32LE(0); // reserved1
        $out->appendU32LE(0); // reserved2
        $out->appendU32LE(0); // reserved3

        // LC_MAIN
        $out->appendU32LE(self::LC_MAIN);
        $out->appendU32LE(24); // cmdsize
        $out->appendU64LE($entryoff);
        $out->appendU64LE(0); // stacksize

        $out->append($code->toString());
        return $out->toString();
    }

    private function cstr(string $s, int $len): PicoString
    {
        return (new PicoString($s))->substring(0, $len - 1)->padRight($len, 0);
    }
}

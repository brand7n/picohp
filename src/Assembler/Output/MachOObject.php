<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

use Remodulate\Pico\PicoString;
use Remodulate\Pico\StringBuilder;

/**
 * Serializes instruction words into a Mach-O arm64 relocatable object file
 * (MH_OBJECT) with a single __text section and one global symbol. Link with
 * Apple's `ld` to produce a signed, runnable executable:
 *
 *   ld -arch arm64 -e _main -o out out.o
 */
final class MachOObject
{
    private const MH_MAGIC_64 = 0xFEEDFACF;
    private const CPU_TYPE_ARM64 = 0x0100000C;
    private const MH_OBJECT = 0x1;
    private const LC_SEGMENT_64 = 0x19;
    private const LC_SYMTAB = 0x2;
    private const N_SECT = 0x0E;
    private const N_EXT = 0x01;

    /**
     * @param list<int> $words
     * @param int $entryAddr byte offset of the entry within __text
     */
    public function encode(array $words, PicoString $entrySymbol, int $entryAddr): PicoString
    {
        $code = new StringBuilder();
        foreach ($words as $word) {
            $code->appendU32LE($word);
        }
        $codeSize = $code->length();

        $textOffset = 32 + 152 + 24; // header + segment + symtab
        $symtabOffset = $textOffset + $codeSize;
        $strtabOffset = $symtabOffset + 16; // one nlist_64

        $out = new StringBuilder();
        // mach_header_64
        $out->appendU32LE(self::MH_MAGIC_64);
        $out->appendU32LE(self::CPU_TYPE_ARM64);
        $out->appendU32LE(0); // cpusubtype
        $out->appendU32LE(self::MH_OBJECT);
        $out->appendU32LE(2); // ncmds
        $out->appendU32LE(176); // sizeofcmds
        $out->appendU32LE(0); // flags
        $out->appendU32LE(0); // reserved

        // LC_SEGMENT_64 __TEXT
        $out->appendU32LE(self::LC_SEGMENT_64);
        $out->appendU32LE(152);
        $out->append($this->cstr('__TEXT', 16));
        $out->appendU64LE(0); // vmaddr
        $out->appendU64LE($codeSize); // vmsize
        $out->appendU64LE(0); // fileoff
        $out->appendU64LE($codeSize); // filesize
        $out->appendU32LE(7); // maxprot
        $out->appendU32LE(5); // initprot
        $out->appendU32LE(1); // nsects
        $out->appendU32LE(0); // flags

        // section __text
        $out->append($this->cstr('__text', 16));
        $out->append($this->cstr('__TEXT', 16));
        $out->appendU64LE(0); // addr
        $out->appendU64LE($codeSize); // size
        $out->appendU32LE($textOffset); // offset
        $out->appendU32LE(2); // align
        $out->appendU32LE(0); // reloff
        $out->appendU32LE(0); // nreloc
        $out->appendU32LE(0x80000400); // flags
        $out->appendU32LE(0); // reserved1
        $out->appendU32LE(0); // reserved2
        $out->appendU32LE(0); // reserved3

        // LC_SYMTAB
        $out->appendU32LE(self::LC_SYMTAB);
        $out->appendU32LE(24);
        $out->appendU32LE($symtabOffset); // symoff
        $out->appendU32LE(1); // nsyms
        $out->appendU32LE($strtabOffset); // stroff
        $out->appendU32LE($entrySymbol->length() + 2); // strsize

        $out->append($code->toString());

        // nlist_64: n_strx, n_type, n_sect, n_desc, n_value
        $out->appendU32LE(1); // n_strx -> "_main" at offset 1
        $out->appendChar(self::N_SECT | self::N_EXT); // n_type: global, defined in section
        $out->appendChar(1); // n_sect: section 1
        $out->appendU16LE(0); // n_desc
        $out->appendU64LE($entryAddr); // n_value: byte offset within __text

        // string table: "\0" + "_main\0"
        $out->appendChar(0);
        $out->append($entrySymbol);
        $out->appendChar(0);

        return $out->toString();
    }

    private function cstr(string $s, int $len): PicoString
    {
        return (new PicoString($s))->substring(0, $len - 1)->padRight($len, 0);
    }
}

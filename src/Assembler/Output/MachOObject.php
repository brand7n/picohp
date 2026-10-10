<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

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
     * @param string $entrySymbol symbol name (e.g. "_main")
     * @param int $entryAddr byte offset of the entry within __text
     */
    public function encode(array $words, string $entrySymbol, int $entryAddr): string
    {
        $code = '';
        foreach ($words as $word) {
            $code .= pack('V', $word);
        }
        $codeSize = strlen($code);

        $textOffset = 32 + 152 + 24; // header + segment + symtab
        $symtabOffset = $textOffset + $codeSize;
        $strtabOffset = $symtabOffset + 16; // one nlist_64

        $out = '';
        // mach_header_64
        $out .= pack('V', self::MH_MAGIC_64);
        $out .= pack('V', self::CPU_TYPE_ARM64);
        $out .= pack('V', 0); // cpusubtype
        $out .= pack('V', self::MH_OBJECT);
        $out .= pack('V', 2); // ncmds
        $out .= pack('V', 176); // sizeofcmds
        $out .= pack('V', 0); // flags
        $out .= pack('V', 0); // reserved

        // LC_SEGMENT_64 __TEXT
        $out .= pack('V', self::LC_SEGMENT_64);
        $out .= pack('V', 152);
        $out .= $this->cstr('__TEXT', 16);
        $out .= pack('Q', 0); // vmaddr
        $out .= pack('Q', $codeSize); // vmsize
        $out .= pack('Q', 0); // fileoff
        $out .= pack('Q', $codeSize); // filesize
        $out .= pack('V', 7); // maxprot
        $out .= pack('V', 5); // initprot
        $out .= pack('V', 1); // nsects
        $out .= pack('V', 0); // flags

        // section __text
        $out .= $this->cstr('__text', 16);
        $out .= $this->cstr('__TEXT', 16);
        $out .= pack('Q', 0); // addr
        $out .= pack('Q', $codeSize); // size
        $out .= pack('V', $textOffset); // offset
        $out .= pack('V', 2); // align
        $out .= pack('V', 0); // reloff
        $out .= pack('V', 0); // nreloc
        $out .= pack('V', 0x80000400); // flags
        $out .= pack('V', 0); // reserved1
        $out .= pack('V', 0); // reserved2
        $out .= pack('V', 0); // reserved3

        // LC_SYMTAB
        $out .= pack('V', self::LC_SYMTAB);
        $out .= pack('V', 24);
        $out .= pack('V', $symtabOffset); // symoff
        $out .= pack('V', 1); // nsyms
        $out .= pack('V', $strtabOffset); // stroff
        $out .= pack('V', strlen($entrySymbol) + 2); // strsize

        $out .= $code;

        // nlist_64: n_strx, n_type, n_sect, n_desc, n_value
        $out .= pack('V', 1); // n_strx -> "_main" at offset 1
        $out .= pack('C', self::N_SECT | self::N_EXT); // n_type: global, defined in section
        $out .= pack('C', 1); // n_sect: section 1
        $out .= pack('v', 0); // n_desc
        $out .= pack('Q', $entryAddr); // n_value: byte offset within __text

        // string table: "\0" + "_main\0"
        $out .= "\0" . $entrySymbol . "\0";

        return $out;
    }

    private function cstr(string $s, int $len): string
    {
        return str_pad(substr($s, 0, $len - 1), $len, "\0");
    }
}

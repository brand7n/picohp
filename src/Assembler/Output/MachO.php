<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

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
    public function encode(array $words, int $entry): string
    {
        $code = '';
        foreach ($words as $word) {
            $code .= pack('V', $word);
        }
        $codeSize = strlen($code);

        $textOffset = 32 + 152 + 24; // header + segment + LC_MAIN
        $textAddr = self::VM_BASE;
        $vmsize = (int) (ceil($codeSize / self::PAGE) * self::PAGE);
        $entryoff = $textOffset + $entry;

        $flags = self::MH_PIE | self::MH_TWOLEVEL | self::MH_DYLDLINK | self::MH_NOUNDEFS;

        $out = '';
        // mach_header_64
        $out .= pack('V', self::MH_MAGIC_64);
        $out .= pack('V', self::CPU_TYPE_ARM64);
        $out .= pack('V', 0); // cpusubtype
        $out .= pack('V', self::MH_EXECUTE);
        $out .= pack('V', 2); // ncmds
        $out .= pack('V', 176); // sizeofcmds
        $out .= pack('V', $flags);
        $out .= pack('V', 0); // reserved

        // LC_SEGMENT_64 __TEXT
        $out .= pack('V', self::LC_SEGMENT_64);
        $out .= pack('V', 152); // cmdsize
        $out .= $this->cstr('__TEXT', 16);
        $out .= pack('Q', $textAddr); // vmaddr
        $out .= pack('Q', $vmsize); // vmsize
        $out .= pack('Q', 0); // fileoff
        $out .= pack('Q', $codeSize); // filesize
        $out .= pack('V', 7); // maxprot
        $out .= pack('V', 5); // initprot (r-x)
        $out .= pack('V', 1); // nsects
        $out .= pack('V', 0); // flags

        // section __text
        $out .= $this->cstr('__text', 16);
        $out .= $this->cstr('__TEXT', 16);
        $out .= pack('Q', $textAddr); // addr
        $out .= pack('Q', $codeSize); // size
        $out .= pack('V', $textOffset); // offset
        $out .= pack('V', 2); // align (2^2 = 4)
        $out .= pack('V', 0); // reloff
        $out .= pack('V', 0); // nreloc
        $out .= pack('V', 0x80000400); // flags: S_ATTR_PURE_INSTRUCTIONS | S_REGULAR
        $out .= pack('V', 0); // reserved1
        $out .= pack('V', 0); // reserved2
        $out .= pack('V', 0); // reserved3

        // LC_MAIN
        $out .= pack('V', self::LC_MAIN);
        $out .= pack('V', 24); // cmdsize
        $out .= pack('Q', $entryoff);
        $out .= pack('Q', 0); // stacksize

        $out .= $code;
        return $out;
    }

    private function cstr(string $s, int $len): string
    {
        return str_pad(substr($s, 0, $len - 1), $len, "\0");
    }
}

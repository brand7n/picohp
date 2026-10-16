<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

/**
 * Serializes instruction words into an ELF64 aarch64 relocatable object file
 * (ET_REL) with a single .text section and one global symbol. Link with GNU
 * `ld`/`gcc`/`clang` on Linux:
 *
 *   clang -o out out.o
 */
final class ELFObject
{
    private const ET_REL = 1;
    private const EM_AARCH64 = 183;
    private const SHT_PROGBITS = 1;
    private const SHT_SYMTAB = 2;
    private const SHT_STRTAB = 3;
    private const SHF_ALLOC = 0x2;
    private const SHF_EXECINSTR = 0x4;
    private const STB_GLOBAL = 1;
    private const STT_FUNC = 2;

    /**
     * @param list<int> $words
     * @param string $entrySymbol symbol name (e.g. "_main")
     * @param int $entryAddr byte offset of the entry within .text
     */
    public function encode(array $words, string $entrySymbol, int $entryAddr): string
    {
        $code = '';
        foreach ($words as $word) {
            $code .= pack('V', $word);
        }
        $codeSize = strlen($code);

        $textOffset = 64; // ELF header
        $symtabOffset = $textOffset + $codeSize;
        $strtabSize = strlen($entrySymbol) + 2; // "\0" + name + "\0"
        $strtabOffset = $symtabOffset + 48; // null sym + one Elf64_Sym
        $shstrtabSize = 33; // "\0.text\0.symtab\0.strtab\0.shstrtab\0"
        $shstrtabOffset = $strtabOffset + $strtabSize;
        $shoff = $shstrtabOffset + $shstrtabSize;

        $out = '';
        // ELF header
        $out .= "\x7fELF";
        $out .= pack('C', 2); // EI_CLASS: 64-bit
        $out .= pack('C', 1); // EI_DATA: little-endian
        $out .= pack('C', 1); // EI_VERSION
        $out .= pack('C', 0); // EI_OSABI: System V
        $out .= pack('C', 0); // EI_ABIVERSION
        $out .= str_repeat("\0", 7); // EI_PAD
        $out .= pack('v', self::ET_REL);
        $out .= pack('v', self::EM_AARCH64);
        $out .= pack('V', 1); // e_version
        $out .= pack('Q', 0); // e_entry
        $out .= pack('Q', 0); // e_phoff
        $out .= pack('Q', $shoff); // e_shoff
        $out .= pack('V', 0); // e_flags
        $out .= pack('v', 64); // e_ehsize
        $out .= pack('v', 0); // e_phentsize
        $out .= pack('v', 0); // e_phnum
        $out .= pack('v', 64); // e_shentsize
        $out .= pack('v', 5); // e_shnum
        $out .= pack('v', 4); // e_shstrndx

        $out .= $code;

        // Elf64_Sym[0]: null symbol (required by ELF convention)
        $out .= str_repeat("\0", 24);
        // Elf64_Sym[1]: st_name, st_info, st_other, st_shndx, st_value, st_size
        $out .= pack('V', 1); // st_name -> "_main" at offset 1 in .strtab
        $out .= pack('C', (self::STB_GLOBAL << 4) | self::STT_FUNC); // st_info
        $out .= pack('C', 0); // st_other
        $out .= pack('v', 1); // st_shndx: .text
        $out .= pack('Q', $entryAddr); // st_value
        $out .= pack('Q', $codeSize); // st_size

        // .strtab: "\0" + "_main\0"
        $out .= "\0" . $entrySymbol . "\0";

        // .shstrtab
        $out .= "\0.text\0.symtab\0.strtab\0.shstrtab\0";

        // Section header table (5 entries)
        $out .= str_repeat("\0", 64); // [0] null
        $out .= $this->shdr(1, self::SHT_PROGBITS, self::SHF_ALLOC | self::SHF_EXECINSTR, $textOffset, $codeSize, 0, 0, 4, 0);
        $out .= $this->shdr(7, self::SHT_SYMTAB, 0, $symtabOffset, 48, 3, 1, 8, 24);
        $out .= $this->shdr(15, self::SHT_STRTAB, 0, $strtabOffset, $strtabSize, 0, 0, 1, 0);
        $out .= $this->shdr(23, self::SHT_STRTAB, 0, $shstrtabOffset, $shstrtabSize, 0, 0, 1, 0);

        return $out;
    }

    private function shdr(int $name, int $type, int $flags, int $offset, int $size, int $link, int $info, int $align, int $entsize): string
    {
        return pack('V', $name)
            . pack('V', $type)
            . pack('Q', $flags)
            . pack('Q', 0) // sh_addr
            . pack('Q', $offset)
            . pack('Q', $size)
            . pack('V', $link)
            . pack('V', $info)
            . pack('Q', $align)
            . pack('Q', $entsize);
    }
}

<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

use Remodulate\Pico\PicoString;
use Remodulate\Pico\StringBuilder;

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
     * @param int $entryAddr byte offset of the entry within .text
     */
    public function encode(array $words, PicoString $entrySymbol, int $entryAddr): PicoString
    {
        $code = new StringBuilder();
        foreach ($words as $word) {
            $code->appendU32LE($word);
        }
        $codeSize = $code->length();

        $textOffset = 64; // ELF header
        $symtabOffset = $textOffset + $codeSize;
        $strtabSize = $entrySymbol->length() + 2; // "\0" + name + "\0"
        $strtabOffset = $symtabOffset + 48; // null sym + one Elf64_Sym
        $shstrtabSize = 33; // "\0.text\0.symtab\0.strtab\0.shstrtab\0"
        $shstrtabOffset = $strtabOffset + $strtabSize;
        $shoff = $shstrtabOffset + $shstrtabSize;

        $out = new StringBuilder();
        // ELF header
        $out->append(new PicoString("\x7fELF"));
        $out->appendChar(2); // EI_CLASS: 64-bit
        $out->appendChar(1); // EI_DATA: little-endian
        $out->appendChar(1); // EI_VERSION
        $out->appendChar(0); // EI_OSABI: System V
        $out->appendChar(0); // EI_ABIVERSION
        $out->append((new PicoString("\0"))->repeat(7)); // EI_PAD
        $out->appendU16LE(self::ET_REL);
        $out->appendU16LE(self::EM_AARCH64);
        $out->appendU32LE(1); // e_version
        $out->appendU64LE(0); // e_entry
        $out->appendU64LE(0); // e_phoff
        $out->appendU64LE($shoff); // e_shoff
        $out->appendU32LE(0); // e_flags
        $out->appendU16LE(64); // e_ehsize
        $out->appendU16LE(0); // e_phentsize
        $out->appendU16LE(0); // e_phnum
        $out->appendU16LE(64); // e_shentsize
        $out->appendU16LE(5); // e_shnum
        $out->appendU16LE(4); // e_shstrndx

        $out->append($code->toString());

        // Elf64_Sym[0]: null symbol (required by ELF convention)
        $out->append((new PicoString("\0"))->repeat(24));
        // Elf64_Sym[1]: st_name, st_info, st_other, st_shndx, st_value, st_size
        $out->appendU32LE(1); // st_name -> "_main" at offset 1 in .strtab
        $out->appendChar((self::STB_GLOBAL << 4) | self::STT_FUNC); // st_info
        $out->appendChar(0); // st_other
        $out->appendU16LE(1); // st_shndx: .text
        $out->appendU64LE($entryAddr); // st_value
        $out->appendU64LE($codeSize); // st_size

        // .strtab: "\0" + "_main\0"
        $out->appendChar(0);
        $out->append($entrySymbol);
        $out->appendChar(0);

        // .shstrtab
        $out->append(new PicoString("\0.text\0.symtab\0.strtab\0.shstrtab\0"));

        // Section header table (5 entries)
        $out->append((new PicoString("\0"))->repeat(64)); // [0] null
        $this->shdr($out, 1, self::SHT_PROGBITS, self::SHF_ALLOC | self::SHF_EXECINSTR, $textOffset, $codeSize, 0, 0, 4, 0);
        $this->shdr($out, 7, self::SHT_SYMTAB, 0, $symtabOffset, 48, 3, 1, 8, 24);
        $this->shdr($out, 15, self::SHT_STRTAB, 0, $strtabOffset, $strtabSize, 0, 0, 1, 0);
        $this->shdr($out, 23, self::SHT_STRTAB, 0, $shstrtabOffset, $shstrtabSize, 0, 0, 1, 0);

        return $out->toString();
    }

    private function shdr(StringBuilder $out, int $name, int $type, int $flags, int $offset, int $size, int $link, int $info, int $align, int $entsize): void
    {
        $out->appendU32LE($name);
        $out->appendU32LE($type);
        $out->appendU64LE($flags);
        $out->appendU64LE(0); // sh_addr
        $out->appendU64LE($offset);
        $out->appendU64LE($size);
        $out->appendU32LE($link);
        $out->appendU32LE($info);
        $out->appendU64LE($align);
        $out->appendU64LE($entsize);
    }
}

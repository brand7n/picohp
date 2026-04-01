<?php

declare(strict_types=1);

namespace PhpParser;

/**
 * Stub for self-compilation: constants only, no array-valued TO_STRING_MAP or methods that
 * reference it. The real class is pulled in via Node classes that `use PhpParser\Modifiers`.
 */
class Modifiers
{
    public const PUBLIC = 1;
    public const PROTECTED = 2;
    public const PRIVATE = 4;
    public const STATIC = 8;
    public const ABSTRACT = 16;
    public const FINAL = 32;
    public const READONLY = 64;
    public const PUBLIC_SET = 128;
    public const PROTECTED_SET = 256;
    public const PRIVATE_SET = 512;

    public const VISIBILITY_MASK = 1 | 2 | 4;
}

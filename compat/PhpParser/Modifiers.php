<?php

declare(strict_types=1);

namespace PhpParser;

/**
 * picoHP compat: replaces constant-array TO_STRING_MAP with switch-based toString().
 */
final class Modifiers
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
    public const VISIBILITY_SET_MASK = 128 | 256 | 512;

    public static function toString(int $modifier): string
    {
        switch ($modifier) {
            case self::PUBLIC:
                return 'public';
            case self::PROTECTED:
                return 'protected';
            case self::PRIVATE:
                return 'private';
            case self::STATIC:
                return 'static';
            case self::ABSTRACT:
                return 'abstract';
            case self::FINAL:
                return 'final';
            case self::READONLY:
                return 'readonly';
            case self::PUBLIC_SET:
                return 'public(set)';
            case self::PROTECTED_SET:
                return 'protected(set)';
            case self::PRIVATE_SET:
                return 'private(set)';
            default:
                return 'unknown';
        }
    }

    public static function verifyClassModifier(int $a, int $b): void
    {
        if (($a & $b) !== 0) {
            throw new Error('Multiple ' . self::toString($b) . ' modifiers are not allowed');
        }
        if (($a & 48) !== 0 && ($b & 48) !== 0) {
            throw new Error('Cannot use the final modifier on an abstract class');
        }
    }

    public static function verifyModifier(int $a, int $b): void
    {
        if (($a & self::VISIBILITY_MASK) !== 0 && ($b & self::VISIBILITY_MASK) !== 0) {
            throw new Error('Multiple access type modifiers are not allowed');
        }
        if (($a & self::VISIBILITY_SET_MASK) !== 0 && ($b & self::VISIBILITY_SET_MASK) !== 0) {
            throw new Error('Multiple access type modifiers are not allowed');
        }
        if (($a & $b) !== 0) {
            throw new Error('Multiple ' . self::toString($b) . ' modifiers are not allowed');
        }
        if (($a & 48) !== 0 && ($b & 48) !== 0) {
            throw new Error('Cannot use the final modifier on an abstract class member');
        }
    }
}

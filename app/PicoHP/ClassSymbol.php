<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * FQCN keys for the class registry vs LLVM-safe identifiers (no {@code \}).
 */
final class ClassSymbol
{
    public static function fqcn(?string $namespacePrefix, string $shortName): string
    {
        if ($namespacePrefix === null || $namespacePrefix === '') {
            return $shortName;
        }

        return $namespacePrefix . '\\' . $shortName;
    }

    /**
     * Prefer php-parser {@code resolvedName} / {@code namespacedName} (when {@see NameResolver} uses
     * {@code replaceNodes: false}), else qualify with {@code $namespaceFallback} for a single-part name.
     */
    public static function fqcnFromResolvedName(\PhpParser\Node\Name $name, ?string $namespaceFallback = null): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof \PhpParser\Node\Name) {
            return $resolved->toString();
        }
        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof \PhpParser\Node\Name) {
            return $namespaced->toString();
        }
        if ($name instanceof \PhpParser\Node\Name\FullyQualified) {
            return $name->toString();
        }

        return self::fqcn($namespaceFallback, $name->getLast());
    }

    public static function mangle(string $fqcn): string
    {
        return str_replace('\\', '_', $fqcn);
    }

    public static function llvmMethodSymbol(string $ownerFqcn, string $methodName): string
    {
        $m = self::mangle($ownerFqcn);
        if ($methodName === '__construct') {
            return $m . '___construct';
        }

        return $m . '_' . $methodName;
    }
}

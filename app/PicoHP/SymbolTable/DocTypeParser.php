<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use App\PicoHP\PicoType;

/**
 * Lightweight regex-based PHPDoc type parser.
 * Handles @var, @return, @param for the type shapes picoHP uses.
 */
class DocTypeParser
{
    public function __construct()
    {
    }

    public function parseType(string $docString): PicoType
    {
        if ($docString === '' || preg_match('/\S/', $docString) !== 1) {
            throw new \RuntimeException('Empty PHPDoc string');
        }
        $type = $this->extractTagType($docString, 'var');
        if ($type !== null) {
            return $this->parseTypeString($type) ?? PicoType::fromString('mixed');
        }

        if (preg_match('/@picobuf/', $docString) === 1) {
            return PicoType::fromString('string');
        }

        return PicoType::fromString('mixed');
    }

    public function parseReturnTypeFromPhpDoc(string $docString): ?PicoType
    {
        $type = $this->extractTagType($docString, 'return');
        if ($type !== null) {
            return $this->parseTypeString($type);
        }

        return null;
    }

    public function parseParamTypeByName(string $docString, string $paramName): ?PicoType
    {
        $bare = ltrim($paramName, '$');
        // Match @param <type> $name — type may contain angle brackets with spaces
        if (preg_match('/@param\s+(.+?)\s+\$' . preg_quote($bare, '/') . '(?:\s|$)/', $docString, $m) === 1) {
            return $this->parseTypeString(trim($m[1]));
        }

        return null;
    }

    /**
     * Extract the type string after a @tag, handling angle brackets that may contain spaces.
     */
    private function extractTagType(string $docString, string $tag): ?string
    {
        $marker = '@' . $tag;
        $pos = strpos($docString, $marker);
        if ($pos === false) {
            return null;
        }
        $start = $pos + strlen($marker);
        $len = strlen($docString);
        // Skip whitespace after tag
        while ($start < $len && ($docString[$start] === ' ' || $docString[$start] === "\t")) {
            $start++;
        }
        if ($start >= $len) {
            return null;
        }
        // Read until we hit whitespace at depth 0 or end
        $depth = 0;
        $end = $start;
        while ($end < $len) {
            $ch = $docString[$end];
            if ($ch === '<') {
                $depth++;
            } elseif ($ch === '>') {
                $depth--;
            } elseif ($depth === 0 && ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === '*')) {
                break;
            }
            $end++;
        }
        $result = substr($docString, $start, $end - $start);

        return $result !== '' ? $result : null;
    }

    private function parseTypeString(string $type): ?PicoType
    {
        // Type[] shorthand (e.g. int[], Token[], string[])
        if (str_ends_with($type, '[]')) {
            $inner = substr($type, 0, -2);

            return PicoType::array(PicoType::fromString($inner));
        }

        // array<KeyType, ValueType> or array<Type>
        if (preg_match('/^array<(.+)>$/', $type, $m) === 1) {
            $inner = $m[1];
            $parts = $this->splitTopLevelComma($inner);
            if (count($parts) === 2) {
                $arr = PicoType::array(PicoType::fromString(trim($parts[1])));
                if (trim($parts[0]) === 'string') {
                    $arr->setStringKeys();
                }

                return $arr;
            }
            if (count($parts) !== 1) {
                return null;
            }

            return PicoType::array(PicoType::fromString(trim($inner)));
        }

        // list<Type>
        if (preg_match('/^list<(.+)>$/', $type, $m) === 1) {
            $parts = $this->splitTopLevelComma($m[1]);
            if (count($parts) !== 1) {
                return null;
            }

            return PicoType::array(PicoType::fromString(trim($m[1])));
        }

        // Nullable ?Type — only simple identifiers
        if (str_starts_with($type, '?')) {
            $inner = substr($type, 1);
            if (str_contains($inner, '<') || str_contains($inner, '|')) {
                return null;
            }

            return PicoType::fromString($type);
        }

        // Union Type1|Type2 — not supported
        if (str_contains($type, '|')) {
            return null;
        }

        return PicoType::fromString($type);
    }

    /**
     * Split by top-level commas (not inside angle brackets).
     *
     * @return list<string>
     */
    private function splitTopLevelComma(string $s): array
    {
        $depth = 0;
        $parts = [];
        $current = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === '<') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === '>') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
            $i++;
        }
        $parts[] = $current;

        return $parts;
    }
}

<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Formats a node for error messages (file + line + snippet when {@code pico_source_file} is set).
 */
final class AstContextFormatter
{
    /**
     * Compact location for invariant messages: absolute {@code path:line} when available,
     * otherwise {@code line N}, otherwise {@code unknown location}.
     */
    public static function location(Node $node): string
    {
        $file = $node->getAttribute('pico_source_file');
        $line = $node->getStartLine();
        if (is_string($file) && $file !== '' && $line > 0) {
            return $file . ':' . $line;
        }
        if (is_string($file) && $file !== '') {
            return $file;
        }
        if ($line > 0) {
            return 'line ' . $line;
        }

        return 'unknown location';
    }

    public static function format(Node $node): string
    {
        $parts = [];
        $file = $node->getAttribute('pico_source_file');
        if (is_string($file) && $file !== '') {
            $parts[] = 'file: ' . $file;
        }
        $line = $node->getStartLine();
        if ($line > 0) {
            $parts[] = 'line: ' . $line;
        }
        $printer = new Standard();
        try {
            if ($node instanceof Node\Expr) {
                $parts[] = 'code: ' . $printer->prettyPrintExpr($node);
            } else {
                $parts[] = 'code: ' . $printer->prettyPrint([$node]);
            }
        } catch (\Throwable) {
            $parts[] = 'code: (could not pretty-print)';
        }

        return implode(', ', $parts);
    }
}

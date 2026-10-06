<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

/**
 * Loads a php-parser JSON AST from disk.
 *
 * php-parser's jsonSerialize() produces a top-level *list* of statement nodes
 * (no root wrapper). We normalize it to a root object: {"nodeType":"_Root","stmts":[...]}
 */
final class AstLoader
{
    public static function load(string $path): AstNode
    {
        if (!is_file($path)) {
            throw new \RuntimeException("AST file not found: {$path}");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read AST file: {$path}");
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("AST JSON must be an array or object: {$path}");
        }

        // Bare list of statement nodes → wrap in a root node
        if (array_is_list($decoded)) {
            $decoded = ['nodeType' => '_Root', 'attributes' => [], 'stmts' => $decoded];
        }

        return new AstNode($decoded);
    }
}

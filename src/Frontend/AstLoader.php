<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use Remodulate\Pico\{Collection, File, Json, PicoString, Value};

/**
 * Loads a php-parser JSON AST from disk via the pico runtime
 * (File::readContents + Json::parse → Value tree).
 *
 * php-parser's jsonSerialize() produces a top-level *list* of statement nodes
 * (no root wrapper). We normalize it to a root map: {"nodeType":"_Root","stmts":[...]}
 */
final class AstLoader
{
    public static function load(string $path): AstNode
    {
        if (!File::isFile(new PicoString($path))) {
            throw new \RuntimeException("AST file not found: {$path}");
        }
        $json = File::readContents(new PicoString($path));
        $decoded = Json::parse($json);

        if ($decoded->tag() === Value::ARRAY) {
            // Bare list of statement nodes → wrap in a root node
            /** @var Collection<Value> $stmts */
            $stmts = $decoded->asArray();
            $root = new Collection();
            $root->set('nodeType', Value::fromString(new PicoString('_Root')));
            $root->set('stmts', Value::fromArray($stmts));
            return new AstNode(Value::fromMap($root));
        }

        if ($decoded->tag() === Value::MAP) {
            return new AstNode($decoded);
        }

        throw new \RuntimeException("AST JSON must be an array or object: {$path}");
    }
}

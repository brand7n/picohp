<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Tags every node with {@code pico_source_file} so compiler passes can report file paths.
 */
final class SourceFileAttributeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private readonly string $sourceFileAbsolute,
    ) {
    }

    /** @return null */
    public function enterNode(Node $node)
    {
        $node->setAttribute('pico_source_file', $this->sourceFileAbsolute);

        return null;
    }
}

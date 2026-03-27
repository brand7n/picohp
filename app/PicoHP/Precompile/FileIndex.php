<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Per-file dependency index (declarations and references). Populated by a future AST visitor.
 */
final class FileIndex
{
    public function __construct(
        public string $filePath = '',
        /** @var list<string> */
        public array $declaredClasses = [],
        /** @var list<string> */
        public array $declaredFunctions = [],
        /** @var list<string> */
        public array $referencedClasses = [],
        /** @var list<string> */
        public array $requiredFiles = [],
    ) {
    }
}

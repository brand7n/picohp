<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * Thrown when an internal picohp invariant is violated (unexpected AST shape,
 * missing symbol table entry, etc.). Distinct from user-facing compile errors.
 */
final class CompilerInvariantException extends \LogicException
{
}

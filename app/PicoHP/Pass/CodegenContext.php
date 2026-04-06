<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\LLVM\Function_;
use App\PicoHP\LLVM\Value\Label;
use App\PicoHP\LLVM\ValueAbstract;

/**
 * Mutable codegen context that tracks where we are in the AST during IR emission.
 *
 * Bundled so that buildStmt can save/restore the entire context atomically
 * when entering nested scopes (class bodies, methods, future closures).
 */
final class CodegenContext
{
    /** The LLVM function currently being emitted into. */
    public ?Function_ $function = null;

    /** FQCN of the class whose body is being emitted. */
    public ?string $className = null;

    /** Alloca holding $this inside a class method. */
    public ?ValueAbstract $thisPtr = null;

    /**
     * When inside a try block, holds the exception ptr alloca and catch dispatch label.
     * Calls to throwing functions branch here on error.
     *
     * @var array{exceptionSlot: ValueAbstract, catchLabel: Label}|null
     */
    public ?array $tryContext = null;

    /** Snapshot the current state so it can be restored after a nested scope. */
    public function save(): self
    {
        $copy = new self();
        $copy->function = $this->function;
        $copy->className = $this->className;
        $copy->thisPtr = $this->thisPtr;
        $copy->tryContext = $this->tryContext;
        return $copy;
    }

    /** Restore from a previously saved snapshot. */
    public function restore(self $saved): void
    {
        $this->function = $saved->function;
        $this->className = $saved->className;
        $this->thisPtr = $saved->thisPtr;
        $this->tryContext = $saved->tryContext;
    }

    /** Enter a class body scope. */
    public function enterClass(string $fqcn): void
    {
        $this->className = $fqcn;
        $this->thisPtr = null;
    }

    /** Leave a class body scope (prefer save/restore instead). */
    public function leaveClass(): void
    {
        $this->className = null;
        $this->thisPtr = null;
    }
}

<?php

/**
 * picoHP builtin exception class hierarchy.
 *
 * Parsed at compiler startup to register exception classes in the ClassRegistry
 * with their properties, methods, and inheritance chain.
 */

declare(strict_types=1);

interface Throwable
{
    public function getMessage(): string;
}

class Exception implements Throwable
{
    public string $message;

    public function __construct(string $message)
    {
    }

    public function getMessage(): string
    {
    }

    public function getTraceAsString(): string
    {
    }
}

class RuntimeException extends Exception
{
}

class LogicException extends Exception
{
}

class InvalidArgumentException extends LogicException
{
}

class OutOfRangeException extends RuntimeException
{
}

class OverflowException extends RuntimeException
{
}

class UnderflowException extends RuntimeException
{
}

class LengthException extends LogicException
{
}

class BadMethodCallException extends LogicException
{
}

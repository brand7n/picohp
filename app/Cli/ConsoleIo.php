<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Minimal stderr/stdout helpers for the picohp CLI (no Symfony Console / String component).
 */
final class ConsoleIo
{
    public function __construct(
        private int $verbosity = 0,
    ) {
    }

    public static function fromVerbosity(int $verbosity): self
    {
        return new self($verbosity);
    }

    public function isVerbose(): bool
    {
        return $this->verbosity >= 1;
    }

    public function isVeryVerbose(): bool
    {
        return $this->verbosity >= 2;
    }

    public function error(string $message): void
    {
        fwrite(STDERR, "\n [ERROR] " . $message . "\n\n");
    }

    public function warning(string $message): void
    {
        fwrite(STDERR, "\n [WARNING] " . $message . "\n\n");
    }

    public function note(string $message): void
    {
        fwrite(STDERR, "\n ! " . $message . "\n\n");
    }

    public function writeln(string $message = ''): void
    {
        echo $message."\n";
    }

    /**
     * Plain text line (SymfonyStyle used {@code <comment>} tags; strip for terminals).
     */
    public function text(string $message): void
    {
        echo preg_replace('/<[^>]+>/', '', $message)."\n";
    }

    public function newLine(int $count = 1): void
    {
        if ($count > 0) {
            echo str_repeat("\n", $count);
        }
    }

    /** @return \Closure(string): void */
    public function createSemanticWarningCallback(): \Closure
    {
        return function (string $message): void {
            $this->warning($message);
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use App\Commands\BuildCommand;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run {@see BuildCommand} as if invoked as {@code picohp <arguments>} (argument string is everything after the binary name).
     */
    protected function assertPicohpExitCode(string $arguments, int $expectedExitCode = 0): void
    {
        $split = preg_split('/\s+/', trim($arguments));
        /** @var list<string> $tokens */
        $tokens = $split === false ? [] : $split;
        $argv = ['picohp', ...$tokens];
        self::assertSame($expectedExitCode, BuildCommand::runFromArgv($argv));
    }
}

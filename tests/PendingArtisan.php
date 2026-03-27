<?php

declare(strict_types=1);

namespace Tests;

use App\Commands\BuildCommand;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PendingArtisan
{
    public function __construct(
        private readonly string $command,
    ) {
    }

    public function assertExitCode(int $expected): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new BuildCommand());
        $input = new StringInput($this->command);
        $output = new BufferedOutput();
        $exitCode = $application->run($input, $output);
        Assert::assertSame($expected, $exitCode);
    }
}

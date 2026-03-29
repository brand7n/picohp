<?php

declare(strict_types=1);

use App\Commands\BuildCommand;

it('prints usage and returns 1 when argv is too short', function () {
    expect(BuildCommand::runFromArgv(['picohp']))->toBe(1);
});

it('returns 1 for a non-build subcommand', function () {
    expect(BuildCommand::runFromArgv(['picohp', 'other']))->toBe(1);
});

it('returns 1 when option parsing fails', function () {
    expect(BuildCommand::runFromArgv(['picohp', 'build', '--not-a-real-flag']))->toBe(1);
});

it('returns 1 when no input file or directory is given', function () {
    expect(BuildCommand::runFromArgv(['picohp', 'build', '--debug']))->toBe(1);
});

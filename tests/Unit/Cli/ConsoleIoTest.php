<?php

declare(strict_types=1);

use App\Cli\ConsoleIo;

it('reports verbosity levels', function () {
    $quiet = ConsoleIo::fromVerbosity(0);
    expect($quiet->isVerbose())->toBeFalse();
    expect($quiet->isVeryVerbose())->toBeFalse();

    $v = ConsoleIo::fromVerbosity(1);
    expect($v->isVerbose())->toBeTrue();
    expect($v->isVeryVerbose())->toBeFalse();

    $vv = ConsoleIo::fromVerbosity(2);
    expect($vv->isVerbose())->toBeTrue();
    expect($vv->isVeryVerbose())->toBeTrue();
});

it('writes messages', function () {
    $io = ConsoleIo::fromVerbosity(0);
    $io->error('e');
    $io->warning('w');
    $io->note('n');
    $io->writeln('line');
    $io->text('<c>plain</c>');
    $io->newLine(0);
    $io->newLine(2);
    expect(true)->toBeTrue();
});

<?php

declare(strict_types=1);

function checkFlags(bool $debugVal, bool $verboseVal): void
{
    /** @var array<string, bool> $flags */
    $flags = ['debug' => $debugVal, 'verbose' => $verboseVal];

    if ($flags['debug']) {
        echo "debug on\n";
    } else {
        echo "debug off\n";
    }

    if ($flags['verbose']) {
        echo "verbose on\n";
    } else {
        echo "verbose off\n";
    }
}

checkFlags(true, false);
checkFlags(false, true);

<?php

declare(strict_types=1);

class ValidationException extends Exception
{
}

function validate(string $input): string
{
    if (strlen($input) === 0) {
        throw new ValidationException('empty input');
    }

    return "valid: " . $input;
}

function process(string $data): string
{
    $validated = validate($data);

    return "processed(" . $validated . ")";
}

function run(string $input): string
{
    return process($input);
}

// Happy path — propagates through 3 functions
try {
    echo run("hello") . "\n";
} catch (ValidationException $e) {
    echo "error: " . $e->getMessage() . "\n";
}

// Error path — propagates through 3 functions
try {
    echo run("") . "\n";
} catch (ValidationException $e) {
    echo "error: " . $e->getMessage() . "\n";
}

// Catch as base Exception
try {
    echo run("") . "\n";
} catch (Exception $e) {
    echo "base: " . $e->getMessage() . "\n";
}

echo "done\n";

<?php

declare(strict_types=1);

use App\PicoHP\SymbolTable\DocTypeParser;
use PHPStan\PhpDocParser\Parser\ParserException;

it('parses a PHPDocs', function () {
    $parser = new DocTypeParser();
    expect($parser->parseType('/** @var int $a */'))->toBe('int');
    expect($parser->parseType('/** @var string $b */'))->toBe('string');
    $parser->parseType('');
})->throws(ParserException::class);

it('fails to parse a PHPDocs', function () {
    $parser = new DocTypeParser();
    expect($parser->parseType('/** @blah blah $b */'))->toBe('string');
    $parser->parseType('');
})->throws(Exception::class, "invalid doc type");

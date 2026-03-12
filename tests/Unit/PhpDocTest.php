<?php

declare(strict_types=1);

use App\PicoHP\SymbolTable\DocTypeParser;
use PHPStan\PhpDocParser\Parser\ParserException;

it('parses a PHPDocs', function () {
    $parser = new DocTypeParser();
    expect($parser->parseType('/** @var int $a */')->toString())->toBe('int');
    expect($parser->parseType('/** @var string $b */')->toString())->toBe('string');
    expect($parser->parseType('/** @picobuf 256 $c */')->toString())->toBe('string');
    $parser->parseType('');
})->throws(ParserException::class);

it('fails to parse an empty PHPDoc', function () {
    $parser = new DocTypeParser();
    $parser->parseType('');
})->throws(ParserException::class);

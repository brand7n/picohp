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

it('parses PicoType::fromString for array generics', function () {
    $arr = \App\PicoHP\PicoType::fromString('array<string, int>');
    expect($arr->isArray())->toBeTrue();
    expect($arr->hasStringKeys())->toBeTrue();
    expect($arr->getElementBaseType())->toBe(\App\PicoHP\BaseType::INT);

    $arr2 = \App\PicoHP\PicoType::fromString('array<int, string>');
    expect($arr2->isArray())->toBeTrue();
    expect($arr2->hasStringKeys())->toBeFalse();

    $bare = \App\PicoHP\PicoType::fromString('array');
    expect($bare->isArray())->toBeTrue();
});

it('fails to parse an empty PHPDoc', function () {
    $parser = new DocTypeParser();
    $parser->parseType('');
})->throws(ParserException::class);

<?php

declare(strict_types=1);

use App\PicoHP\SymbolTable\DocTypeParser;

it('parses a PHPDocs', function () {
    $parser = new DocTypeParser();
    expect($parser->parseType('/** @var int $a */')->toString())->toBe('int');
    expect($parser->parseType('/** @var string $b */')->toString())->toBe('string');
    expect($parser->parseType('/** @picobuf 256 $c */')->toString())->toBe('string');
    $parser->parseType('');
})->throws(\RuntimeException::class);

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
})->throws(\RuntimeException::class);

it('returns null for unsupported union @return types', function () {
    $parser = new DocTypeParser();
    expect($parser->parseReturnTypeFromPhpDoc('/** @return int|string */'))->toBeNull();
});

it('parses @return from a method docblock', function () {
    $parser = new DocTypeParser();
    $t = $parser->parseReturnTypeFromPhpDoc('/** @return int */');
    expect($t)->not->toBeNull();
    expect($t->toString())->toBe('int');
});

it('returns null when there is no single @return', function () {
    $parser = new DocTypeParser();
    expect($parser->parseReturnTypeFromPhpDoc('/** */'))->toBeNull();
});

it('parses nullable @return types', function () {
    $parser = new DocTypeParser();
    $t = $parser->parseReturnTypeFromPhpDoc('/** @return ?string */');
    expect($t)->not->toBeNull();
    expect($t->toString())->toBe('?string');
});

it('parses generic array @return types', function () {
    $parser = new DocTypeParser();
    $t = $parser->parseReturnTypeFromPhpDoc('/** @return array<int, string> */');
    expect($t)->not->toBeNull();
    expect($t->isArray())->toBeTrue();
});

it('parses single-type generic array @return', function () {
    $parser = new DocTypeParser();
    $t = $parser->parseReturnTypeFromPhpDoc('/** @return array<int> */');
    expect($t)->not->toBeNull();
    expect($t->isArray())->toBeTrue();
});

it('parses @param type by name', function () {
    $parser = new DocTypeParser();
    $doc = '/**
     * @param list<int> $items
     * @param string $label
     */';
    $list = $parser->parseParamTypeByName($doc, 'items');
    expect($list)->not->toBeNull();
    expect($list->isArray())->toBeTrue();

    $label = $parser->parseParamTypeByName($doc, 'label');
    expect($label)->not->toBeNull();
    expect($label->toString())->toBe('string');

    expect($parser->parseParamTypeByName($doc, 'missing'))->toBeNull();
});

it('parses list<> @return types', function () {
    $parser = new DocTypeParser();
    $t = $parser->parseReturnTypeFromPhpDoc('/** @return list<string> */');
    expect($t)->not->toBeNull();
    expect($t->isArray())->toBeTrue();
});

it('returns null for unsupported @return generic shapes', function () {
    $parser = new DocTypeParser();
    expect($parser->parseReturnTypeFromPhpDoc('/** @return array<string, string, int> */'))->toBeNull();
});

it('returns null for list<> with wrong arity in @return', function () {
    $parser = new DocTypeParser();
    expect($parser->parseReturnTypeFromPhpDoc('/** @return list<int, int> */'))->toBeNull();
});

it('returns null for nullable non-identifier in @return', function () {
    $parser = new DocTypeParser();
    expect($parser->parseReturnTypeFromPhpDoc('/** @return ?array<int> */'))->toBeNull();
});

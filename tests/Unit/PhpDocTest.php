<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

it('parses a PHPDocs', function () {
    // basic setup

    $config = new ParserConfig(usedAttributes: []);
    $lexer = new Lexer($config);
    $constExprParser = new ConstExprParser($config);
    $typeParser = new TypeParser($config, $constExprParser);
    $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);

    // parsing and reading a PHPDoc string

    $tokens = new TokenIterator($lexer->tokenize('/** @var int $a */'));
    $phpDocNode = $phpDocParser->parse($tokens); // PhpDocNode
    $varTags = $phpDocNode->getVarTagValues(); // ParamTagValueNode[]

    expect($varTags[0]->variableName)->toBe('$a');
    expect($varTags[0]->type->__toString())->toBe('int');
});

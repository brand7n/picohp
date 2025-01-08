<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

class DocTypeParser
{
    protected PhpDocParser $phpDocParser;
    protected Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    public function parseType(string $docString): string
    {
        // parsing and reading a PHPDoc string
        $tokens = new TokenIterator($this->lexer->tokenize($docString));
        $phpDocNode = $this->phpDocParser->parse($tokens); // PhpDocNode
        $varTags = $phpDocNode->getVarTagValues(); // ParamTagValueNode[]

        foreach ($varTags as $tag) {
            return (string)$tag->type;
        }
        throw new \Exception("invalid doc type");
    }
}

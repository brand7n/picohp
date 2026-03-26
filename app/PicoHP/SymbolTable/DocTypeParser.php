<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode};
use PHPStan\PhpDocParser\Ast\Type\{GenericTypeNode, IdentifierTypeNode};
use App\PicoHP\PicoType;

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

    public function parseType(string $docString): PicoType
    {
        // parsing and reading a PHPDoc string
        $tokens = new TokenIterator($this->lexer->tokenize($docString));
        $phpDocNode = $this->phpDocParser->parse($tokens); // PhpDocNode
        $varTags = $phpDocNode->getVarTagValues(); // ParamTagValueNode[]

        foreach ($varTags as $tag) {
            $typeNode = $tag->type;
            if ($typeNode instanceof GenericTypeNode
                && $typeNode->type->name === 'array'
            ) {
                if (count($typeNode->genericTypes) === 2
                    && $typeNode->genericTypes[1] instanceof IdentifierTypeNode
                ) {
                    $arr = PicoType::array(PicoType::fromString($typeNode->genericTypes[1]->name));
                    if ($typeNode->genericTypes[0] instanceof IdentifierTypeNode
                        && $typeNode->genericTypes[0]->name === 'string'
                    ) {
                        $arr->setStringKeys();
                    }
                    return $arr;
                }
                if (count($typeNode->genericTypes) === 1
                    && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
                ) {
                    return PicoType::array(PicoType::fromString($typeNode->genericTypes[0]->name));
                }
            }
            return PicoType::fromString((string)$typeNode);
        }

        $genericTag = $phpDocNode->getTagsByName('@picobuf');
        if (count($genericTag) === 1
            && $genericTag[0]->name === '@picobuf'
            && $genericTag[0]->value instanceof GenericTagValueNode
        ) {
            // TODO: return something like a type buffer of size 256
            return PicoType::fromString('string');
        }
        // Unknown/unsupported PHPDoc shape for local inference; treat as mixed.
        return PicoType::fromString('mixed');
        //dump($genericTag[0]->value);
        //throw new \Exception("invalid doc type");
    }
}

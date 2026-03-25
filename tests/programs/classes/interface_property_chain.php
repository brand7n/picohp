<?php

declare(strict_types=1);

interface TypeNode
{
    public function describe(): string;
}

class IdentifierTypeNode implements TypeNode
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function describe(): string
    {
        return $this->name;
    }
}

class ArrayTypeNode implements TypeNode
{
    public TypeNode $type;

    public function __construct(TypeNode $type)
    {
        $this->type = $type;
    }

    public function describe(): string
    {
        return "array";
    }
}

class NullableTypeNode implements TypeNode
{
    public TypeNode $type;

    public function __construct(TypeNode $type)
    {
        $this->type = $type;
    }

    public function describe(): string
    {
        return "nullable";
    }
}

/** @var IdentifierTypeNode $intNode */
$intNode = new IdentifierTypeNode("int");

/** @var ArrayTypeNode $arrNode */
$arrNode = new ArrayTypeNode($intNode);

// Access property through concrete type — works
echo $arrNode->type->name;
echo "\n";

// Access through interface-typed property, call method via virtual dispatch
echo $arrNode->type->describe();
echo "\n";

/** @var NullableTypeNode $nullNode */
$nullNode = new NullableTypeNode($intNode);
echo $nullNode->type->name;
echo "\n";
echo $nullNode->type->describe();
echo "\n";

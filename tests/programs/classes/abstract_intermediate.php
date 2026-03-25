<?php

declare(strict_types=1);

abstract class Node
{
    abstract public function kind(): string;
}

abstract class Expr extends Node
{
    public int $line;

    public function __construct(int $line)
    {
        $this->line = $line;
    }
}

class BinaryOp extends Expr
{
    public string $op;

    public function __construct(int $line, string $op)
    {
        parent::__construct($line);
        $this->op = $op;
    }

    public function kind(): string
    {
        return "binary";
    }
}

class UnaryOp extends Expr
{
    public string $op;

    public function __construct(int $line, string $op)
    {
        parent::__construct($line);
        $this->op = $op;
    }

    public function kind(): string
    {
        return "unary";
    }
}

function printNode(Node $n): void
{
    echo $n->kind();
    echo "\n";
}

function printExpr(Expr $e): void
{
    echo $e->line;
    echo " ";
    echo $e->kind();
    echo "\n";
}

/** @var BinaryOp $b */
$b = new BinaryOp(10, "+");
/** @var UnaryOp $u */
$u = new UnaryOp(20, "-");

printNode($b);
printNode($u);
printExpr($b);
printExpr($u);

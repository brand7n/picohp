<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class ClassToFunctionVisitor extends NodeVisitorAbstract
{
    protected ?string $className = null;

    /** @return null|int|Node|Node[] */
    public function enterNode(Node $node)
    {
        // Capture the class name for use in transformations
        if ($node instanceof Node\Stmt\Class_) {
            assert($node->name !== null);
            $this->className = $node->name->name;
        }
        return null;
    }

    /** @return null|int|Node|Node[] */
    public function leaveNode(Node $node)
    {
        // Check if the node is a static property declaration
        if ($node instanceof Node\Stmt\Property && $node->isStatic()) {
            $globalStatements = [];

            foreach ($node->props as $property) {
                assert($property->default !== null);
                // Create a global variable with the same name and value
                $stmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable("{$this->className}_{$property->name->toString()}"),
                        $property->default
                    )
                );
                $stmt->setDocComment(new \PhpParser\Comment\Doc('/** @var int */'));
                $globalStatements[] = $stmt;
            }

            // Return the global variable declarations to replace the static properties
            return $globalStatements;
        }

        // Transform methods into functions
        if ($node instanceof Node\Stmt\ClassMethod && $node->isStatic()) {
            $methodName = $node->name->name;

            // Transform static methods: no `$state` parameter
            $stmts = $node->stmts;
            assert($stmts !== null);
            return new Node\Stmt\Function_(
                "{$this->className}_{$methodName}",
                [
                    'params' => $node->params,
                    'stmts' => $stmts,
                ]
            );
        }

        // Convert static property access (e.g., MyClass::$property)
        if ($node instanceof Node\Expr\StaticPropertyFetch) {
            if ($node->class instanceof Node\Name && $node->class->toString() === $this->className) {
                assert($node->name instanceof Node\VarLikeIdentifier);
                return new Node\Expr\Variable("{$this->className}_{$node->name->toString()}");
            }
        }

        // Convert static method calls (e.g., MyClass::methodName())
        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name && $node->class->toString() === $this->className) {
                assert($node->name instanceof Node\Identifier);
                $name = new Node\Name("{$this->className}_{$node->name}");
                return new Node\Expr\FuncCall($name);
            }
        }

        // Remove the class scope entirely
        if ($node instanceof Node\Stmt\Class_) {
            return $node->stmts; // Return only the class body
        }
        return null;
    }
}

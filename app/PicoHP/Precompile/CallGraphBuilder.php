<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeFinder;

/**
 * Extracts a method-level call graph from parsed ASTs.
 *
 * Each node in the graph is a (FQCN, method) pair or a top-level function name.
 * Edges are: static calls, new expressions, method calls (when the receiver type
 * is a resolved Name), and FQCN references in type hints and instanceof.
 *
 * This does NOT do full type inference — it only follows statically-resolvable names.
 * Method calls on variables (e.g. $this->foo()) are tracked as (currentClass, foo).
 */
final class CallGraphBuilder
{
    /**
     * @param array<Node> $ast  Parsed + name-resolved AST for one file
     * @param string           $file Absolute file path (for diagnostics)
     *
     * @return CallGraphResult
     */
    public function extractFromAst(array $ast, string $file): CallGraphResult
    {
        $finder = new NodeFinder();
        $result = new CallGraphResult();

        // Find all classes and their methods
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $classNode) {
            if ($classNode->name === null) {
                continue; // anonymous class
            }
            $className = $this->resolveClassName($classNode);
            if ($className === null) {
                continue;
            }

            // Register the class itself (even if no methods are called, the type may be needed)
            $result->addClassFile($className, $file);

            // Track parent class
            if ($classNode->extends !== null) {
                $parentName = $this->resolveFullyQualifiedName($classNode->extends);
                if ($parentName !== null) {
                    $result->addEdge($className, '__class__', $parentName, '__class__');
                }
            }

            // Track implemented interfaces
            foreach ($classNode->implements as $iface) {
                $ifaceName = $this->resolveFullyQualifiedName($iface);
                if ($ifaceName !== null) {
                    $result->addEdge($className, '__class__', $ifaceName, '__class__');
                }
            }

            foreach ($classNode->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    continue;
                }
                $methodName = $stmt->name->toString();
                $result->addClassMethod($className, $methodName);
                $this->extractCallsFromStmts($stmt->stmts ?? [], $className, $methodName, $result, $finder);
            }
        }

        // Find all interfaces
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Interface_::class) as $ifaceNode) {
            if ($ifaceNode->name === null) {
                continue;
            }
            $ifaceName = $this->resolveClassName($ifaceNode);
            if ($ifaceName !== null) {
                $result->addClassFile($ifaceName, $file);
            }
        }

        // Find all top-level functions
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Function_::class) as $funcNode) {
            $funcName = $funcNode->name->toString();
            $result->addFunctionFile($funcName, $file);
            $this->extractCallsFromStmts($funcNode->stmts ?? [], '__global__', $funcName, $result, $finder);
        }

        // Top-level code (not inside class or function)
        $topLevelStmts = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_
                || $stmt instanceof Node\Stmt\Interface_
                || $stmt instanceof Node\Stmt\Trait_
                || $stmt instanceof Node\Stmt\Enum_
                || $stmt instanceof Node\Stmt\Function_) {
                continue;
            }
            if ($stmt instanceof Node\Stmt\Namespace_) {
                // Recurse into namespace for nested declarations
                foreach ($stmt->stmts as $nsStmt) {
                    if (!$nsStmt instanceof Node\Stmt\Class_
                        && !$nsStmt instanceof Node\Stmt\Interface_
                        && !$nsStmt instanceof Node\Stmt\Trait_
                        && !$nsStmt instanceof Node\Stmt\Enum_
                        && !$nsStmt instanceof Node\Stmt\Function_) {
                        $topLevelStmts[] = $nsStmt;
                    }
                }
                continue;
            }
            $topLevelStmts[] = $stmt;
        }
        if ($topLevelStmts !== []) {
            $this->extractCallsFromStmts($topLevelStmts, '__global__', '__main__', $result, $finder);
        }

        // Find all enums
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Enum_::class) as $enumNode) {
            if ($enumNode->name === null) {
                continue;
            }
            $enumName = $this->resolveClassName($enumNode);
            if ($enumName !== null) {
                $result->addClassFile($enumName, $file);
            }
        }

        return $result;
    }

    /**
     * @param array<Node> $stmts
     */
    private function extractCallsFromStmts(array $stmts, string $ownerClass, string $ownerMethod, CallGraphResult $result, NodeFinder $finder): void
    {
        // Static calls: Foo::bar()
        foreach ($finder->findInstanceOf($stmts, Node\Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
                $targetClass = $this->resolveFullyQualifiedName($call->class);
                if ($targetClass !== null) {
                    $result->addEdge($ownerClass, $ownerMethod, $targetClass, $call->name->toString());
                }
            }
        }

        // New expressions: new Foo()
        foreach ($finder->findInstanceOf($stmts, Node\Expr\New_::class) as $newExpr) {
            if ($newExpr->class instanceof Node\Name) {
                $targetClass = $this->resolveFullyQualifiedName($newExpr->class);
                if ($targetClass !== null) {
                    $result->addEdge($ownerClass, $ownerMethod, $targetClass, '__construct');
                    // new also makes the class itself reachable
                    $result->addEdge($ownerClass, $ownerMethod, $targetClass, '__class__');
                }
            }
        }

        // Method calls on $this: $this->foo()
        foreach ($finder->findInstanceOf($stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier) {
                $methodName = $call->name->toString();
                // If receiver is $this, target is the current class
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $result->addEdge($ownerClass, $ownerMethod, $ownerClass, $methodName);
                }
                // Otherwise we don't know the receiver type statically — skip
            }
        }

        // Function calls: foo()
        foreach ($finder->findInstanceOf($stmts, Node\Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name) {
                $funcName = $this->resolveFullyQualifiedName($call->name) ?? $call->name->toString();
                $result->addEdge($ownerClass, $ownerMethod, '__global__', $funcName);
            }
        }

        // FQCN references in type hints, instanceof, etc. — just the class, not a specific method
        foreach ($finder->findInstanceOf($stmts, FullyQualified::class) as $nameNode) {
            $fqcn = $nameNode->toString();
            $result->addEdge($ownerClass, $ownerMethod, $fqcn, '__class__');
        }
    }

    private function resolveClassName(Node\Stmt\Class_|Node\Stmt\Interface_|Node\Stmt\Enum_ $node): ?string
    {
        if ($node->name === null) {
            return null;
        }
        $resolved = $node->namespacedName;
        if ($resolved !== null) {
            return $resolved->toString();
        }
        return $node->name->toString();
    }

    private function resolveFullyQualifiedName(Node\Name $name): ?string
    {
        if ($name instanceof FullyQualified) {
            return $name->toString();
        }
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }
        return null;
    }
}

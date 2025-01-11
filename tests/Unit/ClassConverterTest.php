<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

it('converts a class into functions', function () {
    // Step 1: Parse the input code
    $code = <<<'CODE'
    <?php
    class TheClass {
        private int $prop1;
        private int $prop2;

        public function __construct($value) {

        }

        public function setProp1($value)  {
            $this->prop1 = $value;
        }

        public function getProp1() {
            return $this->prop1;
        }

        public static function statictest(): int {
            return 1;
        }
    }

    $myClass = new TheClass();
    TheClass::statictest();
    CODE;

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $ast = $parser->parse($code);

    // Step 2: Transform the AST
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
            // Transform the constructor
            if ($node instanceof Node\Stmt\ClassMethod && $node->name->name === '__construct') {
                // TODO: create $state array and return it
                $newParams = array_merge(
                    [new Node\Param(new Node\Expr\Variable('state'), null, null, true)], // Add $state parameter
                    $node->params
                );

                // Replace $this->prop with $state['prop']
                $this->replaceThisWithState($node);

                $stmts = $node->stmts;
                assert($stmts !== null);
                return new Node\Stmt\Function_(
                    "{$this->className}_constructor",
                    [
                        'params' => $newParams,
                        'stmts' => $stmts,
                    ]
                );
            }

            // Transform methods into functions
            if ($node instanceof Node\Stmt\ClassMethod) {
                $methodName = $node->name->name;

                // Check if the method is static
                $isStatic = $node->isStatic();

                if ($isStatic) {
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

                $newParams = array_merge(
                    [new Node\Param(new Node\Expr\Variable('state'), null, null, true)], // Add $state parameter by reference
                    $node->params // Keep existing parameters
                );

                // Replace $this->prop with $state['prop']
                $this->replaceThisWithState($node);

                // Convert method to a function
                $stmts = $node->stmts;
                assert($stmts !== null);
                return new Node\Stmt\Function_(
                    "{$this->className}_{$methodName}",
                    [
                        'params' => $newParams,
                        'stmts' => $stmts,
                    ]
                );

            }

            // Transform static calls (e.g., MyClass::staticMethod)
            if ($node instanceof Node\Expr\StaticCall) {
                if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                    $className = $node->class->toString();
                    $methodName = $node->name->toString();

                    // Replace with a direct function call
                    return new Node\Expr\FuncCall(
                        new Node\Name("{$className}_{$methodName}"),
                        $node->args
                    );
                }
            }

            // Transform `new` statements
            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                $className = $node->class->toString();

                $stateVar = new Node\Expr\Variable('state');
                $constructorCall = new Node\Expr\FuncCall(
                    new Node\Name($className . "_constructor"),
                    array_merge(
                        [new Node\Arg($stateVar, false, true)], // Pass $state by reference
                        $node->args // Pass constructor arguments
                    )
                );

                return $constructorCall;
            }

            // Remove class properties
            if ($node instanceof Node\Stmt\Property) {
                return NodeTraverser::REMOVE_NODE;
            }

            // Remove the class scope entirely
            if ($node instanceof Node\Stmt\Class_) {
                return $node->stmts; // Return only the class body
            }
            return null;
        }

        private function replaceThisWithState(Node\Stmt\ClassMethod $node): void
        {
            // Traverse the method body and replace $this->prop with $state['prop']
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new class () extends NodeVisitorAbstract {
                public function enterNode(Node $node): ?Node
                {
                    if ($node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                        assert($node->name instanceof \PhpParser\Node\Identifier);
                        return new Node\Expr\ArrayDimFetch(
                            new Node\Expr\Variable('state'),
                            new Node\Scalar\String_($node->name->name)
                        );
                    }
                    return null;
                }
            });
            assert(!is_null($node->stmts));
            // TODO: fix Property PhpParser\Node\Stmt\ClassMethod::$stmts (array<PhpParser\Node\Stmt>|null) 
            // does not accept array<PhpParser\Node> 
            /** @phpstan-ignore-next-line */
            $node->stmts = $traverser->traverse($node->stmts);
        }
    }

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ClassToFunctionVisitor());
    assert(!is_null($ast));
    $transformedAst = $traverser->traverse($ast);
    expect(count($transformedAst))->toBe(6);

    //echo json_encode($transformedAst, JSON_PRETTY_PRINT);

    // Step 3: Generate the transformed PHP code
    //$prettyPrinter = new Standard();
    //$newCode = $prettyPrinter->prettyPrintFile($transformedAst);

    //expect(is_string($newCode))->toBeTrue();
    //echo $newCode . PHP_EOL;
});
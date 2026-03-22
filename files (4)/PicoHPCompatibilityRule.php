<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags PHP constructs that picoHP cannot compile.
 *
 * PHPStan max + strict-rules already catches: variable variables, loose
 * comparisons, backtick execution, implicit array creation, dynamic calls
 * on static methods, missing typehints, mixed types, and dead code.
 *
 * This rule catches everything else that's valid PHP (passes PHPStan) but
 * can't be lowered to LLVM IR by picoHP.
 *
 * @implements Rule<Node>
 */
final class PicoHPCompatibilityRule implements Rule
{
    /** @var list<class-string<Node>> */
    private const FORBIDDEN_NODES = [
        // eval()
        Expr\Eval_::class,

        // goto
        Stmt\Goto_::class,
        Stmt\Label::class,

        // yield / generators
        Expr\Yield_::class,
        Expr\YieldFrom::class,

        // global $var
        Stmt\Global_::class,

        // extract() and compact() are caught via FuncCall check below

        // @ error suppression
        Expr\ErrorSuppress::class,

        // Dynamic class instantiation: new $className()
        // Caught via New_ check below

        // include/require (dynamic paths caught below)
        Stmt\InlineHTML::class,
    ];

    /** @var list<string> */
    private const FORBIDDEN_FUNCTIONS = [
        'eval',
        'extract',
        'compact',
        'call_user_func',
        'call_user_func_array',
        'func_get_args',
        'func_get_arg',
        'func_num_args',
        'create_function',
        'get_defined_vars',
        'get_defined_functions',
        'get_defined_constants',
    ];

    /** @var list<string> */
    private const FORBIDDEN_MAGIC_METHODS = [
        '__get',
        '__set',
        '__call',
        '__callStatic',
        '__isset',
        '__unset',
        '__toString',
        '__invoke',
        '__debugInfo',
        '__serialize',
        '__unserialize',
        '__sleep',
        '__wakeup',
        '__clone',
    ];

    /** @var list<string> */
    private const FORBIDDEN_CLASSES = [
        'ReflectionClass',
        'ReflectionMethod',
        'ReflectionFunction',
        'ReflectionProperty',
        'ReflectionParameter',
        'ReflectionNamedType',
        'ReflectionUnionType',
        'ReflectionIntersectionType',
        'ReflectionType',
        'ReflectionObject',
        'ReflectionEnum',
        'ReflectionFiber',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        // Direct node type checks
        foreach (self::FORBIDDEN_NODES as $forbidden) {
            if ($node instanceof $forbidden) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('picoHP cannot compile %s.', $this->describeNode($node))
                )->identifier('picohp.unsupported')->build();

                return $errors;
            }
        }

        // Forbidden function calls
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toLowerString();
            if (in_array($name, self::FORBIDDEN_FUNCTIONS, true)) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('picoHP cannot compile %s() calls.', $name)
                )->identifier('picohp.unsupported')->build();
            }
        }

        // Dynamic function calls: $func()
        if ($node instanceof Expr\FuncCall && !($node->name instanceof Node\Name)) {
            $errors[] = RuleErrorBuilder::message(
                'picoHP cannot compile dynamic function calls ($func()).'
            )->identifier('picohp.unsupported')->build();
        }

        // Dynamic method calls: $obj->$method()
        if ($node instanceof Expr\MethodCall && !($node->name instanceof Node\Identifier)) {
            $errors[] = RuleErrorBuilder::message(
                'picoHP cannot compile dynamic method calls ($obj->$method()).'
            )->identifier('picohp.unsupported')->build();
        }

        // Dynamic static method calls: ClassName::$method()
        if ($node instanceof Expr\StaticCall && !($node->name instanceof Node\Identifier)) {
            $errors[] = RuleErrorBuilder::message(
                'picoHP cannot compile dynamic static method calls (Class::$method()).'
            )->identifier('picohp.unsupported')->build();
        }

        // Dynamic class instantiation: new $className()
        if ($node instanceof Expr\New_ && !($node->class instanceof Node\Name) && !($node->class instanceof Stmt\Class_)) {
            $errors[] = RuleErrorBuilder::message(
                'picoHP cannot compile dynamic class instantiation (new $className()).'
            )->identifier('picohp.unsupported')->build();
        }

        // Dynamic property access: $obj->$prop
        if ($node instanceof Expr\PropertyFetch && !($node->name instanceof Node\Identifier)) {
            $errors[] = RuleErrorBuilder::message(
                'picoHP cannot compile dynamic property access ($obj->$prop).'
            )->identifier('picohp.unsupported')->build();
        }

        // Magic method definitions
        if ($node instanceof Stmt\ClassMethod) {
            $methodName = $node->name->toString();
            if (in_array($methodName, self::FORBIDDEN_MAGIC_METHODS, true)) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('picoHP cannot compile magic method %s().', $methodName)
                )->identifier('picohp.unsupported')->build();
            }
        }

        // Reflection API usage
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            if (in_array($className, self::FORBIDDEN_CLASSES, true)) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('picoHP cannot compile Reflection API usage (%s).', $className)
                )->identifier('picohp.unsupported')->build();
            }
        }

        // Closure::bind and Closure::fromCallable
        if ($node instanceof Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->class->toString() === 'Closure'
            && $node->name instanceof Node\Identifier
        ) {
            $method = $node->name->toString();
            if ($method === 'bind' || $method === 'fromCallable' || $method === 'bindTo') {
                $errors[] = RuleErrorBuilder::message(
                    sprintf('picoHP cannot compile Closure::%s().', $method)
                )->identifier('picohp.unsupported')->build();
            }
        }

        // include/require with dynamic paths
        if ($node instanceof Expr\Include_) {
            if (!($node->expr instanceof Node\Scalar\String_)) {
                $errors[] = RuleErrorBuilder::message(
                    'picoHP cannot compile dynamic include/require paths.'
                )->identifier('picohp.unsupported')->build();
            }
        }

        return $errors;
    }

    private function describeNode(Node $node): string
    {
        return match (true) {
            $node instanceof Expr\Eval_ => 'eval()',
            $node instanceof Stmt\Goto_ => 'goto statements',
            $node instanceof Stmt\Label => 'goto labels',
            $node instanceof Expr\Yield_ => 'yield (generators)',
            $node instanceof Expr\YieldFrom => 'yield from (generators)',
            $node instanceof Stmt\Global_ => 'global variable declarations',
            $node instanceof Expr\ErrorSuppress => 'the @ error suppression operator',
            $node instanceof Stmt\InlineHTML => 'inline HTML',
            default => $node::class,
        };
    }
}

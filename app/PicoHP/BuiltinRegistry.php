<?php

declare(strict_types=1);

namespace App\PicoHP;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeFinder;
use PhpParser\PhpVersion;

/**
 * Parses PHP header files in builtins/ to produce a map of function name → {@see BuiltinDef}.
 *
 * Used by both SemanticAnalysisPass (return type resolution) and IRGenerationPass (codegen dispatch).
 */
final class BuiltinRegistry
{
    /** @var array<string, BuiltinDef> lowercased function name → definition */
    private array $builtins = [];

    /** @var array<string, BuiltinClassDef> class name (case-sensitive) → definition */
    private array $classes = [];

    private static ?self $defaultInstance = null;

    /**
     * @param array<string, BuiltinDef> $builtins
     * @param array<string, BuiltinClassDef> $classes
     */
    private function __construct(array $builtins, array $classes = [])
    {
        $this->builtins = $builtins;
        $this->classes = $classes;
    }

    public static function createDefault(): self
    {
        if (self::$defaultInstance !== null) {
            return self::$defaultInstance;
        }

        $headerDir = dirname(__DIR__, 2) . '/builtins';
        $registry = self::fromDirectory($headerDir);
        self::$defaultInstance = $registry;

        return $registry;
    }

    public static function fromDirectory(string $path): self
    {
        $builtins = [];
        $classes = [];
        $files = glob($path . '/*.php');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            $ast = self::parseFile($file);
            $finder = new NodeFinder();

            /** @var list<Function_> $functions */
            $functions = $finder->findInstanceOf($ast, Function_::class);
            foreach ($functions as $funcNode) {
                $def = self::buildDef($funcNode);
                $builtins[strtolower($def->name)] = $def;
            }

            /** @var list<Interface_> $interfaceNodes */
            $interfaceNodes = $finder->findInstanceOf($ast, Interface_::class);
            foreach ($interfaceNodes as $ifaceNode) {
                $ifaceDef = self::buildInterfaceDef($ifaceNode);
                $classes[$ifaceDef->name] = $ifaceDef;
            }

            /** @var list<Class_> $classNodes */
            $classNodes = $finder->findInstanceOf($ast, Class_::class);
            foreach ($classNodes as $classNode) {
                $classDef = self::buildClassDef($classNode);
                $classes[$classDef->name] = $classDef;
            }
        }

        return new self($builtins, $classes);
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private static function parseFile(string $filePath): array
    {
        $code = file_get_contents($filePath);
        CompilerInvariant::check($code !== false, "Cannot read builtin header: {$filePath}");

        $lexer = new \PhpParser\Lexer();
        $parser = new \PhpParser\Parser\Php8($lexer, PhpVersion::getNewestSupported());
        $ast = $parser->parse($code);
        CompilerInvariant::check($ast !== null, "Cannot parse builtin header: {$filePath}");

        return $ast;
    }

    private static function buildDef(Function_ $node): BuiltinDef
    {
        $name = $node->name->name;
        $docComment = $node->getDocComment();
        $docText = $docComment !== null ? $docComment->getText() : '';

        // Parse return type
        $returnTypeNode = $node->getReturnType();
        CompilerInvariant::check($returnTypeNode !== null, "Builtin {$name} must have a return type");
        $returnType = self::nodeTypeTopicoType($returnTypeNode);

        // Parse params
        $params = [];
        foreach ($node->params as $param) {
            $paramName = '';
            if ($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name)) {
                $paramName = $param->var->name;
            }
            $paramTypeNode = $param->type;
            CompilerInvariant::check($paramTypeNode !== null, "Builtin {$name} param {$paramName} must have a type");
            $paramType = self::nodeTypeTopicoType($paramTypeNode);

            $hasDefault = $param->default !== null;
            $defaultValue = null;
            if ($param->default instanceof \PhpParser\Node\Scalar\Int_) {
                $defaultValue = $param->default->value;
            } elseif ($param->default instanceof \PhpParser\Node\Scalar\Float_) {
                $defaultValue = $param->default->value;
            } elseif ($param->default instanceof \PhpParser\Node\Expr\UnaryMinus
                && $param->default->expr instanceof \PhpParser\Node\Scalar\Int_) {
                $defaultValue = -$param->default->expr->value;
            } elseif ($param->default instanceof \PhpParser\Node\Expr\UnaryMinus
                && $param->default->expr instanceof \PhpParser\Node\Scalar\Float_) {
                $defaultValue = -$param->default->expr->value;
            } elseif ($param->default instanceof \PhpParser\Node\Scalar\String_) {
                $defaultValue = $param->default->value;
            } elseif ($param->default instanceof \PhpParser\Node\Expr\Array_) {
                $defaultValue = null; // array default handled specially
                $hasDefault = true;
            }

            $params[] = [
                'name' => $paramName,
                'type' => $paramType,
                'hasDefault' => $hasDefault,
                'defaultValue' => $defaultValue,
            ];
        }

        // Parse annotations
        $runtimeSymbol = self::parseAnnotation($docText, 'runtime-symbol');
        $intrinsic = self::parseAnnotation($docText, 'intrinsic');
        $returnMatchesArgStr = self::parseAnnotation($docText, 'return-matches-arg');
        $returnElementTypeStr = self::parseAnnotation($docText, 'return-element-type');

        $requiredCount = 0;
        foreach ($params as $p) {
            if (!$p['hasDefault']) {
                $requiredCount++;
            }
        }

        return new BuiltinDef(
            name: $name,
            returnType: $returnType,
            params: $params,
            runtimeSymbol: $runtimeSymbol,
            intrinsic: $intrinsic,
            returnMatchesArg: $returnMatchesArgStr !== null ? (int) $returnMatchesArgStr : null,
            returnElementType: $returnElementTypeStr !== null ? (int) $returnElementTypeStr : null,
            requiredCount: $requiredCount,
        );
    }

    private static function buildInterfaceDef(Interface_ $node): BuiltinClassDef
    {
        $name = $node->name !== null ? $node->name->name : '';
        CompilerInvariant::check($name !== '', 'Builtin interface must have a name');

        $methods = [];
        foreach ($node->getMethods() as $methodNode) {
            $methodName = $methodNode->name->name;
            $returnTypeNode = $methodNode->getReturnType();
            $returnType = $returnTypeNode !== null
                ? self::nodeTypeTopicoType($returnTypeNode)
                : PicoType::fromString('void');

            $params = [];
            foreach ($methodNode->params as $param) {
                $paramName = '';
                if ($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name)) {
                    $paramName = $param->var->name;
                }
                $paramTypeNode = $param->type;
                $paramType = $paramTypeNode !== null
                    ? self::nodeTypeTopicoType($paramTypeNode)
                    : PicoType::fromString('mixed');
                $params[] = ['name' => $paramName, 'type' => $paramType];
            }

            $methods[$methodName] = new BuiltinMethodDef(
                name: $methodName,
                returnType: $returnType,
                params: $params,
            );
        }

        return new BuiltinClassDef(
            name: $name,
            parentName: null,
            properties: [],
            methods: $methods,
            isInterface: true,
        );
    }

    private static function buildClassDef(Class_ $node): BuiltinClassDef
    {
        $name = $node->name !== null ? $node->name->name : '';
        CompilerInvariant::check($name !== '', 'Builtin class must have a name');

        $parentName = $node->extends !== null ? $node->extends->toString() : null;

        $interfaces = [];
        foreach ($node->implements as $iface) {
            $interfaces[] = $iface->toString();
        }

        $properties = [];
        foreach ($node->getProperties() as $propGroup) {
            $propType = $propGroup->type !== null
                ? self::nodeTypeTopicoType($propGroup->type)
                : PicoType::fromString('mixed');
            foreach ($propGroup->props as $prop) {
                $properties[$prop->name->name] = $propType;
            }
        }

        $methods = [];
        foreach ($node->getMethods() as $methodNode) {
            $methodName = $methodNode->name->name;
            $returnTypeNode = $methodNode->getReturnType();
            $returnType = $returnTypeNode !== null
                ? self::nodeTypeTopicoType($returnTypeNode)
                : PicoType::fromString('void');

            $params = [];
            foreach ($methodNode->params as $param) {
                $paramName = '';
                if ($param->var instanceof \PhpParser\Node\Expr\Variable && is_string($param->var->name)) {
                    $paramName = $param->var->name;
                }
                $paramTypeNode = $param->type;
                $paramType = $paramTypeNode !== null
                    ? self::nodeTypeTopicoType($paramTypeNode)
                    : PicoType::fromString('mixed');
                $params[] = ['name' => $paramName, 'type' => $paramType];
            }

            $methods[$methodName] = new BuiltinMethodDef(
                name: $methodName,
                returnType: $returnType,
                params: $params,
            );
        }

        return new BuiltinClassDef(
            name: $name,
            parentName: $parentName,
            properties: $properties,
            methods: $methods,
            interfaces: $interfaces,
        );
    }

    private static function nodeTypeTopicoType(\PhpParser\Node $typeNode): PicoType
    {
        if ($typeNode instanceof \PhpParser\Node\Identifier) {
            $typeName = $typeNode->name;

            return match ($typeName) {
                'int' => PicoType::fromString('int'),
                'float' => PicoType::fromString('float'),
                'string' => PicoType::fromString('string'),
                'bool' => PicoType::fromString('bool'),
                'void' => PicoType::fromString('void'),
                'array' => PicoType::fromString('array'),
                'mixed' => PicoType::fromString('mixed'),
                default => throw new \RuntimeException("Unknown builtin type: {$typeName}"),
            };
        }

        if ($typeNode instanceof \PhpParser\Node\NullableType) {
            $inner = self::nodeTypeTopicoType($typeNode->type);
            $innerStr = $inner->toBase()->value;

            return PicoType::fromString("?{$innerStr}");
        }

        throw new \RuntimeException('Unsupported type node in builtin header: ' . $typeNode::class);
    }

    private static function parseAnnotation(string $docText, string $tag): ?string
    {
        $pattern = '/@' . preg_quote($tag, '/') . '\s+(\S+)/';
        /** @var array<int, string> $m */
        $m = [];
        if (preg_match($pattern, $docText, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function has(string $funcName): bool
    {
        return isset($this->builtins[strtolower($funcName)]);
    }

    public function get(string $funcName): BuiltinDef
    {
        $key = strtolower($funcName);
        CompilerInvariant::check(isset($this->builtins[$key]), "Builtin not found: {$funcName}");

        return $this->builtins[$key];
    }

    /**
     * @return array<string, BuiltinDef>
     */
    public function all(): array
    {
        return $this->builtins;
    }

    public function hasClass(string $className): bool
    {
        return isset($this->classes[$className]);
    }

    public function getClass(string $className): BuiltinClassDef
    {
        CompilerInvariant::check(isset($this->classes[$className]), "Builtin class not found: {$className}");

        return $this->classes[$className];
    }

    /**
     * @return array<string, BuiltinClassDef>
     */
    public function allClasses(): array
    {
        return $this->classes;
    }

    public static function resetDefaultInstance(): void
    {
        self::$defaultInstance = null;
    }
}

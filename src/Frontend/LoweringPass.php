<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use PicoIR\{BaseType, Builder, Constant, Function_, Label, Module, NullConstant, Param, ValueAbstract};
use PicoIR\Instruction\AllocaInstruction;

/**
 * Lowers a php-parser JSON AST to PicoIR.
 *
 * Supported slice: int/float/string/bool literals, echo, variables + assignment,
 * arithmetic (+ - * / %), comparisons, boolean ops, if/else, while, for,
 * function declarations with typed params/returns, function calls, return.
 *
 * PHPStan-resolved types are read from node attribute "resolvedType";
 * declared types are read from Identifier nodes where present.
 */
final class LoweringPass
{
    private Builder $builder;
    private Module $module;
    private Scope $scope;
    private ?Function_ $currentFunction = null;

    /** @var array<string, Function_> */
    private array $functions = [];

    /** @var array<string, list<BaseType>> function name => param types */
    private array $signatures = [];

    /**
     * @var array<string, array{
     *   typeId: int,
     *   fields: array<string, int>,
     *   fieldTypes: array<string, BaseType>,
     *   ctor: ?string,
     * }> class name => layout
     */
    private array $classes = [];

    /** @var array<string, array{fn: Function_, node: AstNode, class: string}> mangled name => method */
    private array $methods = [];

    /** @var array<string, array<string, int>> enum name => case name => tag */
    private array $enums = [];

    /** @var array<string, string> variable name => class name */
    private array $varClasses = [];

    private ?string $currentClass = null;
    private int $nextTypeId = 256;

    public function lower(AstNode $root): Module
    {
        $this->module = new Module('pico_module');
        $this->builder = new Builder($this->module);


        /** @var list<AstNode> $stmts */
        $stmts = [];
        foreach ($root->nodes('') as $n) { // root may be a bare list wrapped in an object
            $stmts[] = $n;
        }
        if ($stmts === []) {
            $stmts = $root->nodes('stmts');
        }

        $this->declareTypes($stmts);
        $this->declareFunctions($stmts);
        $this->declareMethods($stmts);

        $main = $this->module->addFunction('main', BaseType::INT, []);
        $this->currentFunction = $main;
        $this->scope = new Scope();
        $this->builder->setInsertPoint($main->addBasicBlock('entry'));

        $this->lowerStmtList($stmts);

        if (!$this->builder->getInsertPoint()?->hasTerminator()) {
            $this->builder->createRet(new Constant(0, BaseType::INT));
        }

        foreach ($this->methods as $method) {
            $this->lowerMethod($method['class'], $method['node'], $method['fn']);
        }

        return $this->module;
    }

    // ── Type declarations (classes, enums) ─────────────────────────

    /** @param list<AstNode> $stmts */
    private function declareTypes(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            match ($stmt->type()) {
                'Stmt_Enum' => $this->declareEnum($stmt),
                'Stmt_Class' => $this->declareClass($stmt),
                default => null,
            };
        }
    }

    private function declareEnum(AstNode $stmt): void
    {
        // ponytail: enum cases are plain i64 tags; add case objects when ->value/->name is needed.
        $name = $stmt->name();
        $tag = 0;
        foreach ($stmt->nodes('stmts') as $case) {
            if ($case->type() !== 'Stmt_EnumCase') {
                continue;
            }
            $this->enums[$name][$case->name()] = $tag++;
        }
    }

    private function declareClass(AstNode $stmt): void
    {
        $name = $stmt->name();
        if ($stmt->node('extends') !== null) {
            throw $this->error($stmt, 'inheritance unsupported');
        }
        $typeId = $this->nextTypeId++;
        $fields = [];
        $fieldTypes = [];

        foreach ($stmt->nodes('stmts') as $member) {
            if ($member->type() !== 'Stmt_Property') {
                continue;
            }
            $type = TypeMapper::map($this->typeName($member->node('type')));
            foreach ($member->nodes('props') as $prop) {
                $pname = $prop->name();
                $fields[$pname] = count($fields) + 2;
                $fieldTypes[$pname] = $type;
            }
        }

        $ctor = null;
        foreach ($stmt->nodes('stmts') as $member) {
            if ($member->type() !== 'Stmt_ClassMethod' || $member->name() !== '__construct') {
                continue;
            }
            $ctor = "__construct";
            foreach ($member->nodes('params') as $param) {
                if (($param->int('flags') & 7) === 0) {
                    continue; // not a promoted property
                }
                $pname = $param->node('var')?->name() ?? '';
                $fields[$pname] ??= count($fields) + 2;
                $fieldTypes[$pname] = TypeMapper::map($this->paramType($param));
            }
        }

        $this->classes[$name] = [
            'typeId' => $typeId,
            'fields' => $fields,
            'fieldTypes' => $fieldTypes,
            'ctor' => $ctor,
        ];

        $layout = ['i64', 'i64']; // { type_id, ref_count }
        foreach ($fields as $pname => $_) {
            $layout[] = $fieldTypes[$pname]->toLLVM();
        }
        $this->module->addHeaderLine("%struct.{$name} = type { " . implode(', ', $layout) . ' }');
    }

    /** @param list<AstNode> $stmts */
    private function declareMethods(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->type() !== 'Stmt_Class') {
                continue;
            }
            $className = $stmt->name();
            foreach ($stmt->nodes('stmts') as $member) {
                if ($member->type() !== 'Stmt_ClassMethod') {
                    continue;
                }
                $mangled = "{$className}__{$member->name()}";
                $paramTypes = [BaseType::PTR];
                foreach ($member->nodes('params') as $param) {
                    $paramTypes[] = TypeMapper::map($this->paramType($param));
                }
                $isCtor = in_array($member->name(), ['__construct', '__destruct'], true);
                $returnType = $isCtor ? BaseType::VOID : TypeMapper::map($this->returnType($member));
                $fn = $this->module->addFunction($mangled, $returnType, $paramTypes);
                $this->methods[$mangled] = ['fn' => $fn, 'node' => $member, 'class' => $className];
            }
        }
    }

    private function lowerMethod(string $className, AstNode $member, Function_ $fn): void
    {
        $fnScope = new Scope();
        $prevFn = $this->currentFunction;
        $prevPoint = $this->builder->getInsertPoint();
        $prevClass = $this->currentClass;
        $prevVarClasses = $this->varClasses;

        $this->currentFunction = $fn;
        $this->currentClass = $className;
        $this->varClasses = [];
        $this->builder->setInsertPoint($fn->addBasicBlock('entry'));

        $thisSlot = $this->alloca(BaseType::PTR, 'this');
        $this->builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
        $fnScope->define('this', $thisSlot);

        foreach ($member->nodes('params') as $i => $param) {
            $varName = $param->node('var')?->name() ?? "p{$i}";
            $type = TypeMapper::map($this->paramType($param));
            $slot = $this->alloca($type, $varName);
            $this->builder->createStore(new Param($i + 1, $type), $slot);
            $fnScope->define($varName, $slot);
            $declared = $this->typeName($param->node('type'));
            if ($declared !== null && isset($this->classes[$declared])) {
                $this->varClasses[$varName] = $declared;
            }
        }

        $this->promoteConstructorParams($className, $member, $fnScope, $thisSlot);

        $savedScope = $this->scope;
        $this->scope = $fnScope;
        $this->lowerStmtList($member->nodes('stmts'));
        $this->scope = $savedScope;

        if (!$this->builder->getInsertPoint()?->hasTerminator()) {
            if ($fn->getReturnType() === BaseType::VOID) {
                $this->builder->createRetVoid();
            } else {
                throw new \RuntimeException("method {$fn->getName()}: missing return on all paths");
            }
        }

        $this->currentFunction = $prevFn;
        $this->currentClass = $prevClass;
        $this->varClasses = $prevVarClasses;
        if ($prevPoint !== null) {
            $this->builder->setInsertPoint($prevPoint);
        }
    }

    /** Store promoted constructor parameters into their object fields. */
    private function promoteConstructorParams(string $className, AstNode $member, Scope $scope, ValueAbstract $thisSlot): void
    {
        if ($member->name() !== '__construct') {
            return;
        }
        $info = $this->classes[$className];
        foreach ($member->nodes('params') as $param) {
            if (($param->int('flags') & 7) === 0) {
                continue;
            }
            $pname = $param->node('var')?->name() ?? '';
            $index = $info['fields'][$pname] ?? null;
            $slot = $pname !== '' ? $scope->lookup($pname) : null;
            if ($index === null || $slot === null) {
                continue;
            }
            $obj = $this->builder->createLoad($thisSlot);
            $ptr = $this->builder->createStructGEP($className, $obj, $index, $info['fieldTypes'][$pname]);
            $this->builder->createStore($this->builder->createLoad($slot), $ptr);
        }
    }

    /** @param list<AstNode> $stmts */
    private function declareFunctions(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->type() !== 'Stmt_Function') {
                continue;
            }
            $name = $stmt->name();
            $paramTypes = [];
            foreach ($stmt->nodes('params') as $param) {
                $paramTypes[] = TypeMapper::map($this->paramType($param));
            }
            $returnType = TypeMapper::map($this->returnType($stmt));
            $this->signatures[$name] = $paramTypes;
            $this->functions[$name] = $this->module->addFunction($name, $returnType, $paramTypes);
        }
    }

    /** @param list<AstNode> $stmts */
    private function lowerStmtList(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->lowerStmt($stmt);
        }
    }

    private function lowerStmt(AstNode $stmt): void
    {
        $this->builder->setDebugLine($stmt->line() > 0 ? $stmt->line() : null);

        match ($stmt->type()) {
            'Stmt_Function' => $this->lowerFunction($stmt),
            'Stmt_Expression' => $this->lowerExpr($stmt->node('expr') ?? throw $this->error($stmt)),
            'Stmt_Echo' => $this->lowerEcho($stmt),
            'Stmt_If' => $this->lowerIf($stmt),
            'Stmt_While' => $this->lowerWhile($stmt),
            'Stmt_For' => $this->lowerFor($stmt),
            'Stmt_Return' => $this->lowerReturn($stmt),
            'Stmt_Declare', 'Stmt_Nop' => null,
            'Stmt_Class', 'Stmt_Enum' => null,
            default => throw $this->error($stmt, 'unsupported statement'),
        };
    }

    private function lowerFunction(AstNode $stmt): void
    {
        $name = $stmt->name();
        $fn = $this->functions[$name];

        $fnScope = new Scope();
        $prevFn = $this->currentFunction;
        $prevPoint = $this->builder->getInsertPoint();
        $this->currentFunction = $fn;

        $entry = $fn->addBasicBlock('entry');
        $this->builder->setInsertPoint($entry);

        foreach ($stmt->nodes('params') as $i => $param) {
            $varName = $param->node('var')?->name() ?? "p{$i}";
            $type = TypeMapper::map($this->paramType($param));
            $slot = $this->alloca($type, $varName);
            $this->builder->createStore(new Param($i, $type), $slot);
            $fnScope->define($varName, $slot);
        }

        $savedScope = $this->scope;
        $this->scope = $fnScope;
        $this->lowerStmtList($stmt->nodes('stmts'));
        $this->scope = $savedScope;

        if (!$this->builder->getInsertPoint()?->hasTerminator()) {
            if ($fn->getReturnType() === BaseType::VOID) {
                $this->builder->createRetVoid();
            } else {
                throw new \RuntimeException("function {$name}: missing return on all paths");
            }
        }

        $this->currentFunction = $prevFn;
        if ($prevPoint !== null) {
            $this->builder->setInsertPoint($prevPoint);
        }
    }

    private function lowerEcho(AstNode $stmt): void
    {
        foreach ($stmt->nodes('exprs') as $expr) {
            $this->emitEcho($this->lowerExpr($expr), $expr);
        }
    }

    /** `echo` lowers to the typed runtime output function. */
    private function emitEcho(ValueAbstract $value, AstNode $expr): void
    {
        match ($value->getType()) {
            BaseType::INT => $this->builder->createRuntimeCall('pico_echo_int', [$value], BaseType::INT, 'i64 (i64)'),
            BaseType::FLOAT => $this->builder->createRuntimeCall('pico_echo_float', [$value], BaseType::INT, 'i64 (double)'),
            BaseType::BOOL => $this->builder->createRuntimeCall('pico_echo_bool', [$this->builder->createZext($value)], BaseType::INT, 'i64 (i64)'),
            BaseType::STRING, BaseType::PTR => $this->builder->createRuntimeCall('pico_echo_str', [$value], BaseType::INT, 'i64 (ptr)'),
            default => throw $this->error($expr, "echo of {$value->getType()->value}"),
        };
    }

    private function lowerIf(AstNode $stmt): void
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('if outside function');
        $cond = $this->lowerExpr($stmt->node('cond') ?? throw $this->error($stmt));
        $thenBlock = $fn->addBasicBlock('if.then');
        $elseBlock = $fn->addBasicBlock('if.else');
        $mergeBlock = $fn->addBasicBlock('if.merge');

        $this->builder->createCondBr($cond, $this->label($thenBlock), $this->label($elseBlock));

        $this->builder->setInsertPoint($thenBlock);
        $this->lowerStmtList($stmt->nodes('stmts'));
        if (!$thenBlock->hasTerminator()) {
            $this->builder->createBr($this->label($mergeBlock));
        }

        $this->builder->setInsertPoint($elseBlock);
        $elseIfs = $stmt->nodes('elseifs');
        if ($elseIfs !== []) {
            $this->lowerElseIfChain($elseIfs, $mergeBlock);
        } else {
            $else = $stmt->node('else');
            if ($else !== null) {
                $this->lowerStmtList($else->nodes('stmts'));
            }
        }
        if (!$elseBlock->hasTerminator()) {
            $this->builder->createBr($this->label($mergeBlock));
        }

        $this->builder->setInsertPoint($mergeBlock);
    }

    /** @param list<AstNode> $elseIfs */
    private function lowerElseIfChain(array $elseIfs, \PicoIR\BasicBlock $mergeBlock): void
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('elseif outside function');
        $first = array_shift($elseIfs);
        if ($first === null) {
            return;
        }
        $cond = $this->lowerExpr($first->node('cond') ?? throw $this->error($first));
        $thenBlock = $fn->addBasicBlock('elseif.then');
        $nextBlock = $fn->addBasicBlock('elseif.next');
        $this->builder->createCondBr($cond, $this->label($thenBlock), $this->label($nextBlock));

        $this->builder->setInsertPoint($thenBlock);
        $this->lowerStmtList($first->nodes('stmts'));
        if (!$thenBlock->hasTerminator()) {
            $this->builder->createBr($this->label($mergeBlock));
        }

        $this->builder->setInsertPoint($nextBlock);
        if ($elseIfs !== []) {
            $this->lowerElseIfChain($elseIfs, $mergeBlock);
        }
        if (!$nextBlock->hasTerminator()) {
            $this->builder->createBr($this->label($mergeBlock));
        }
    }

    private function lowerWhile(AstNode $stmt): void
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('while outside function');
        $condBlock = $fn->addBasicBlock('while.cond');
        $bodyBlock = $fn->addBasicBlock('while.body');
        $endBlock = $fn->addBasicBlock('while.end');

        $this->builder->createBr($this->label($condBlock));

        $this->builder->setInsertPoint($condBlock);
        $cond = $this->lowerExpr($stmt->node('cond') ?? throw $this->error($stmt));
        $this->builder->createCondBr($cond, $this->label($bodyBlock), $this->label($endBlock));

        $this->builder->setInsertPoint($bodyBlock);
        $this->lowerStmtList($stmt->nodes('stmts'));
        if (!$bodyBlock->hasTerminator()) {
            $this->builder->createBr($this->label($condBlock));
        }

        $this->builder->setInsertPoint($endBlock);
    }

    private function lowerFor(AstNode $stmt): void
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('for outside function');
        foreach ($stmt->nodes('init') as $init) {
            $this->lowerExpr($init);
        }

        $condBlock = $fn->addBasicBlock('for.cond');
        $bodyBlock = $fn->addBasicBlock('for.body');
        $endBlock = $fn->addBasicBlock('for.end');

        $this->builder->createBr($this->label($condBlock));

        $this->builder->setInsertPoint($condBlock);
        $condExprs = $stmt->nodes('cond');
        $cond = $condExprs !== []
            ? $this->lowerExpr($condExprs[0])
            : new Constant(1, BaseType::BOOL);
        $this->builder->createCondBr($cond, $this->label($bodyBlock), $this->label($endBlock));

        $this->builder->setInsertPoint($bodyBlock);
        $this->lowerStmtList($stmt->nodes('stmts'));
        foreach ($stmt->nodes('loop') as $loop) {
            $this->lowerExpr($loop);
        }
        if (!$bodyBlock->hasTerminator()) {
            $this->builder->createBr($this->label($condBlock));
        }

        $this->builder->setInsertPoint($endBlock);
    }

    private function lowerReturn(AstNode $stmt): void
    {
        $expr = $stmt->node('expr');
        if ($expr === null) {
            $this->builder->createRetVoid();
            return;
        }
        $this->builder->createRet($this->lowerExpr($expr));
    }

    /** Create an alloca hoisted to the function entry block (LLVM convention). */
    private function alloca(BaseType $type, string $nameHint): AllocaInstruction
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('alloca outside function');
        $slot = new AllocaInstruction($type, $nameHint);
        $fn->getEntryBlock()->prepend($slot);
        return $slot;
    }

    private function lowerExpr(AstNode $expr): ValueAbstract
    {
        $this->builder->setDebugLine($expr->line() > 0 ? $expr->line() : null);

        return match ($expr->type()) {
            'Scalar_Int' => new Constant($expr->int('value'), BaseType::INT),
            'Scalar_Float' => new Constant($expr->float('value'), BaseType::FLOAT),
            'Scalar_String' => $this->builder->createPicoString($expr->string('value')),
            'Expr_ConstFetch' => $this->lowerConstFetch($expr),
            'Expr_Variable' => $this->lowerVariable($expr),
            'Expr_Assign' => $this->lowerAssign($expr),
            'Expr_BinaryOp_Plus', 'Expr_BinaryOp_Minus', 'Expr_BinaryOp_Mul',
            'Expr_BinaryOp_Div', 'Expr_BinaryOp_Mod' => $this->lowerArithmetic($expr),
            'Expr_BinaryOp_Concat' => $this->lowerConcat($expr),
            'Expr_BinaryOp_Equal', 'Expr_BinaryOp_NotEqual',
            'Expr_BinaryOp_Identical', 'Expr_BinaryOp_NotIdentical',
            'Expr_BinaryOp_Greater', 'Expr_BinaryOp_GreaterOrEqual',
            'Expr_BinaryOp_Smaller', 'Expr_BinaryOp_SmallerOrEqual' => $this->lowerComparison($expr),
            'Expr_BinaryOp_BooleanAnd', 'Expr_BinaryOp_LogicalAnd' => $this->lowerBoolOp($expr, 'and'),
            'Expr_BinaryOp_BooleanOr', 'Expr_BinaryOp_LogicalOr' => $this->lowerBoolOp($expr, 'or'),
            'Expr_BooleanNot' => $this->lowerBoolNot($expr),
            'Expr_FuncCall' => $this->lowerFuncCall($expr),
            'Expr_Ternary' => $this->lowerTernary($expr),
            'Expr_New' => $this->lowerNew($expr),
            'Expr_PropertyFetch' => $this->lowerPropertyFetch($expr),
            'Expr_MethodCall' => $this->lowerMethodCall($expr),
            'Expr_ClassConstFetch' => $this->lowerClassConstFetch($expr),
            'Expr_Match' => $this->lowerMatch($expr),
            default => throw $this->error($expr, 'unsupported expression'),
        };
    }

    private function lowerConstFetch(AstNode $expr): ValueAbstract
    {
        return match (strtolower($expr->name())) {
            'true' => new Constant(1, BaseType::BOOL),
            'false' => new Constant(0, BaseType::BOOL),
            default => throw $this->error($expr, 'unsupported constant'),
        };
    }

    private function lowerVariable(AstNode $expr): ValueAbstract
    {
        $slot = $this->scope->lookup($expr->name())
            ?? throw $this->error($expr, 'undefined variable $' . $expr->name());
        return $this->builder->createLoad($slot);
    }

    private function lowerAssign(AstNode $expr): ValueAbstract
    {
        $var = $expr->node('var') ?? throw $this->error($expr);
        if ($var->type() === 'Expr_PropertyFetch') {
            return $this->lowerPropertyAssign($var, $expr->node('expr') ?? throw $this->error($expr));
        }
        if ($var->type() !== 'Expr_Variable') {
            throw $this->error($expr, 'only simple variable assignment supported');
        }
        $init = $expr->node('expr') ?? throw $this->error($expr);
        $value = $this->lowerExpr($init);
        $name = $var->name();
        $slot = $this->scope->lookup($name);
        if ($slot === null) {
            $slot = $this->alloca($value->getType(), $name);
            $this->scope->define($name, $slot);
        }
        $this->builder->createStore($value, $slot);
        if ($init->type() === 'Expr_New') {
            $this->varClasses[$name] = $this->classNameOf($init);
        }
        return $value;
    }

    private function lowerArithmetic(AstNode $expr): ValueAbstract
    {
        $lhs = $this->lowerExpr($expr->node('left') ?? throw $this->error($expr));
        $rhs = $this->lowerExpr($expr->node('right') ?? throw $this->error($expr));
        $float = $lhs->getType() === BaseType::FLOAT || $rhs->getType() === BaseType::FLOAT;

        $opcode = match ($expr->type()) {
            'Expr_BinaryOp_Plus' => $float ? 'fadd' : 'add',
            'Expr_BinaryOp_Minus' => $float ? 'fsub' : 'sub',
            'Expr_BinaryOp_Mul' => $float ? 'fmul' : 'mul',
            'Expr_BinaryOp_Div' => $float ? 'fdiv' : 'sdiv',
            'Expr_BinaryOp_Mod' => 'srem',
            default => throw $this->error($expr),
        };
        return $this->builder->createBinOp($opcode, $lhs, $rhs, $float ? BaseType::FLOAT : null);
    }

    private function lowerConcat(AstNode $expr): ValueAbstract
    {
        $lhs = $this->lowerExpr($expr->node('left') ?? throw $this->error($expr));
        $rhs = $this->lowerExpr($expr->node('right') ?? throw $this->error($expr));
        return $this->builder->createRuntimeCall(
            'pico_str_concat',
            [$lhs, $rhs],
            BaseType::STRING,
            'ptr (ptr, ptr)',
        );
    }

    private function lowerComparison(AstNode $expr): ValueAbstract
    {
        $lhs = $this->lowerExpr($expr->node('left') ?? throw $this->error($expr));
        $rhs = $this->lowerExpr($expr->node('right') ?? throw $this->error($expr));
        $float = $lhs->getType() === BaseType::FLOAT || $rhs->getType() === BaseType::FLOAT;

        $pred = match ($expr->type()) {
            'Expr_BinaryOp_Equal', 'Expr_BinaryOp_Identical' => $float ? 'fcmp oeq' : 'icmp eq',
            'Expr_BinaryOp_NotEqual', 'Expr_BinaryOp_NotIdentical' => $float ? 'fcmp one' : 'icmp ne',
            'Expr_BinaryOp_Greater' => $float ? 'fcmp ogt' : 'icmp sgt',
            'Expr_BinaryOp_GreaterOrEqual' => $float ? 'fcmp oge' : 'icmp sge',
            'Expr_BinaryOp_Smaller' => $float ? 'fcmp olt' : 'icmp slt',
            'Expr_BinaryOp_SmallerOrEqual' => $float ? 'fcmp ole' : 'icmp sle',
            default => throw $this->error($expr),
        };
        return $this->builder->createBinOp($pred, $lhs, $rhs, BaseType::BOOL);
    }

    private function lowerBoolOp(AstNode $expr, string $op): ValueAbstract
    {
        $lhs = $this->lowerExpr($expr->node('left') ?? throw $this->error($expr));
        $rhs = $this->lowerExpr($expr->node('right') ?? throw $this->error($expr));
        return $this->builder->createBinOp($op, $lhs, $rhs, BaseType::BOOL);
    }

    private function lowerBoolNot(AstNode $expr): ValueAbstract
    {
        $operand = $this->lowerExpr($expr->node('expr') ?? throw $this->error($expr));
        return $this->builder->createBinOp('xor', $operand, new Constant(1, BaseType::BOOL), BaseType::BOOL);
    }

    private function lowerFuncCall(AstNode $expr): ValueAbstract
    {
        $nameNode = $expr->node('name') ?? throw $this->error($expr);
        $name = $nameNode->name();

        $args = [];
        foreach ($expr->nodes('args') as $arg) {
            $argExpr = $arg->node('value') ?? throw $this->error($arg);
            $args[] = $this->lowerExpr($argExpr);
        }

        if (isset($this->functions[$name])) {
            $returnType = $this->functions[$name]->getReturnType();
        } else {
            $returnType = TypeMapper::map($expr->resolvedType());
        }

        return $this->builder->createCall($name, $args, $returnType);
    }

    private function lowerTernary(AstNode $expr): ValueAbstract
    {
        $cond = $this->lowerExpr($expr->node('cond') ?? throw $this->error($expr));
        $if = $expr->node('if') ?? throw $this->error($expr, 'short ternary unsupported');
        $true = $this->lowerExpr($if);
        $false = $this->lowerExpr($expr->node('else') ?? throw $this->error($expr));
        return $this->builder->createSelect($cond, $true, $false);
    }

    // ── Objects ────────────────────────────────────────────────────

    private function lowerNew(AstNode $expr): ValueAbstract
    {
        $className = $this->classNameOf($expr);
        $info = $this->classes[$className] ?? throw $this->error($expr, "unknown class {$className}");

        // ponytail: no retain/release yet — allocated objects live for the process lifetime.
        $obj = $this->builder->createObjectAlloc($className, $info['typeId']);
        $this->builder->createStore(
            new Constant($info['typeId'], BaseType::INT),
            $this->builder->createStructGEP($className, $obj, 0, BaseType::INT),
        );
        $this->builder->createStore(
            new Constant(1, BaseType::INT),
            $this->builder->createStructGEP($className, $obj, 1, BaseType::INT),
        );

        $args = [$obj];
        foreach ($expr->nodes('args') as $arg) {
            $args[] = $this->lowerExpr($arg->node('value') ?? throw $this->error($arg));
        }
        if ($info['ctor'] !== null) {
            $ctor = $this->methods["{$className}__{$info['ctor']}"] ?? throw $this->error($expr, 'constructor not declared');
            $this->builder->createCall($ctor['fn']->getName(), $args, BaseType::VOID);
        }

        return $obj;
    }

    private function lowerPropertyFetch(AstNode $expr): ValueAbstract
    {
        return $this->builder->createLoad($this->propertyPtr($expr));
    }

    private function lowerPropertyAssign(AstNode $target, AstNode $valueExpr): ValueAbstract
    {
        $ptr = $this->propertyPtr($target);
        $value = $this->lowerExpr($valueExpr);
        $this->builder->createStore($value, $ptr);
        return $value;
    }

    private function propertyPtr(AstNode $fetch): ValueAbstract
    {
        $varNode = $fetch->node('var') ?? throw $this->error($fetch);
        $className = $this->objectClassOf($varNode);
        $fieldName = $fetch->node('name')?->name() ?? throw $this->error($fetch, 'dynamic property name');
        $info = $this->classes[$className] ?? throw $this->error($fetch, "unknown class {$className}");
        $index = $info['fields'][$fieldName] ?? throw $this->error($fetch, "unknown property \${$fieldName}");
        $obj = $this->lowerExpr($varNode);

        return $this->builder->createStructGEP($className, $obj, $index, $info['fieldTypes'][$fieldName]);
    }

    private function lowerMethodCall(AstNode $expr): ValueAbstract
    {
        $varNode = $expr->node('var') ?? throw $this->error($expr);
        $obj = $this->lowerExpr($varNode);
        $className = $this->objectClassOf($varNode);
        $mangled = "{$className}__" . ($expr->node('name')?->name() ?? throw $this->error($expr));
        $method = $this->methods[$mangled] ?? throw $this->error($expr, "unknown method {$mangled}");

        $args = [$obj];
        foreach ($expr->nodes('args') as $arg) {
            $args[] = $this->lowerExpr($arg->node('value') ?? throw $this->error($arg));
        }

        return $this->builder->createCall($mangled, $args, $method['fn']->getReturnType());
    }

    private function lowerClassConstFetch(AstNode $expr): ValueAbstract
    {
        $className = $this->classNameOf($expr);
        $caseName = $expr->node('name')?->name() ?? throw $this->error($expr);
        $tag = $this->enums[$className][$caseName] ?? throw $this->error($expr, "unknown constant {$className}::{$caseName}");

        return new Constant($tag, BaseType::INT);
    }

    private function lowerMatch(AstNode $expr): ValueAbstract
    {
        $fn = $this->currentFunction ?? throw new \RuntimeException('match outside function');
        $subject = $this->lowerExpr($expr->node('cond') ?? throw $this->error($expr));

        $end = $fn->addBasicBlock('match.end');
        $check = $fn->addBasicBlock('match.check');
        $this->builder->createBr($this->label($check));

        $result = null;
        $hasDefault = false;
        foreach ($expr->nodes('arms') as $i => $arm) {
            $this->builder->setInsertPoint($check);
            $body = $fn->addBasicBlock("match.body{$i}");
            $conds = $arm->nodes('conds');

            if ($conds === []) {
                $hasDefault = true;
                $this->builder->createBr($this->label($body));
            } else {
                $cond = null;
                foreach ($conds as $case) {
                    $eq = $this->equality($subject, $this->lowerExpr($case));
                    $cond = $cond === null ? $eq : $this->builder->createBinOp('or', $cond, $eq, BaseType::BOOL);
                }
                $next = $fn->addBasicBlock('match.check' . ($i + 1));
                $this->builder->createCondBr($cond ?? throw $this->error($expr), $this->label($body), $this->label($next));
                $check = $next;
            }

            $this->builder->setInsertPoint($body);
            $value = $this->lowerExpr($arm->node('body') ?? throw $this->error($arm));
            $result ??= $this->alloca($value->getType(), 'match.result');
            $this->builder->createStore($value, $result);
            $this->builder->createBr($this->label($end));
        }

        $result ??= throw $this->error($expr, 'match without arms');

        if (!$hasDefault) {
            $this->builder->setInsertPoint($check);
            $this->builder->createStore($this->zeroOf($result->getType()), $result);
            $this->builder->createBr($this->label($end));
        }

        $this->builder->setInsertPoint($end);
        return $this->builder->createLoad($result);
    }

    private function equality(ValueAbstract $lhs, ValueAbstract $rhs): ValueAbstract
    {
        if ($lhs->getType() === BaseType::FLOAT) {
            return $this->builder->createBinOp('fcmp oeq', $lhs, $rhs, BaseType::BOOL);
        }
        if ($lhs->getType() === BaseType::STRING || $lhs->getType() === BaseType::PTR) {
            $eq = $this->builder->createRuntimeCall('pico_str_equals', [$lhs, $rhs], BaseType::INT, 'i64 (ptr, ptr)');
            return $this->builder->createICmpNe($eq, new Constant(0, BaseType::INT));
        }
        return $this->builder->createBinOp('icmp eq', $lhs, $rhs, BaseType::BOOL);
    }

    private function zeroOf(BaseType $type): ValueAbstract
    {
        return match ($type) {
            BaseType::FLOAT => new Constant(0.0, BaseType::FLOAT),
            BaseType::INT => new Constant(0, BaseType::INT),
            BaseType::BOOL => new Constant(0, BaseType::BOOL),
            default => new NullConstant($type),
        };
    }

    private function classNameOf(AstNode $node): string
    {
        $class = $node->node('class');
        if ($class !== null) {
            return $class->name();
        }
        return $node->name();
    }

    /** Static class of an object-valued expression. */
    private function objectClassOf(AstNode $expr): string
    {
        if ($expr->type() === 'Expr_Variable') {
            $name = $expr->name();
            if ($name === 'this' && $this->currentClass !== null) {
                return $this->currentClass;
            }
            if (isset($this->varClasses[$name])) {
                return $this->varClasses[$name];
            }
        }
        if ($expr->type() === 'Expr_New') {
            return $this->classNameOf($expr);
        }
        $resolved = $expr->resolvedType();
        if ($resolved !== null) {
            $short = ltrim(explode('|', $resolved)[0], '?\\');
            $short = substr($short, (int) strrpos('\\' . $short, '\\'));
            if (isset($this->classes[$short])) {
                return $short;
            }
        }
        throw $this->error($expr, 'cannot resolve object type');
    }

    private function typeName(?AstNode $node): ?string
    {
        if ($node === null) {
            return null;
        }
        return match ($node->type()) {
            'Identifier' => $node->string('name'),
            'NullableType' => $this->typeName($node->node('type')),
            default => $node->resolvedType(),
        };
    }

    private function paramType(AstNode $param): ?string
    {
        $type = $param->node('type');
        if ($type !== null && $type->type() === 'Identifier') {
            return $type->string('name');
        }
        return $param->node('var')?->resolvedType();
    }

    private function returnType(AstNode $func): ?string
    {
        $type = $func->node('returnType');
        if ($type !== null && $type->type() === 'Identifier') {
            return $type->string('name');
        }
        return $func->resolvedType();
    }

    private function label(\PicoIR\BasicBlock $bb): Label
    {
        return new Label($bb->getName());
    }

    private function error(AstNode $node, string $msg = 'unsupported construct'): \RuntimeException
    {
        return new \RuntimeException("{$msg}: {$node->type()} (line {$node->line()})");
    }
}

<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use PicoIR\{BaseType, Builder, Constant, Function_, Label, Module, Param, ValueAbstract};
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

    public function lower(AstNode $root): Module
    {
        $this->module = new Module('pico_module');
        $this->builder = new Builder($this->module);

        $this->module->addHeaderLine('declare i32 @printf(ptr, ...)');

        /** @var list<AstNode> $stmts */
        $stmts = [];
        foreach ($root->nodes('') as $n) { // root may be a bare list wrapped in an object
            $stmts[] = $n;
        }
        if ($stmts === []) {
            $stmts = $root->nodes('stmts');
        }

        $this->declareFunctions($stmts);

        $main = $this->module->addFunction('main', BaseType::INT, []);
        $this->currentFunction = $main;
        $this->scope = new Scope();
        $this->builder->setInsertPoint($main->addBasicBlock('entry'));

        $this->lowerStmtList($stmts);

        if (!$this->builder->getInsertPoint()?->hasTerminator()) {
            $this->builder->createRet(new Constant(0, BaseType::INT));
        }

        return $this->module;
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
            $value = $this->lowerExpr($expr);
            $fmt = match ($value->getType()) {
                BaseType::INT => "%d",
                BaseType::FLOAT => "%f",
                BaseType::BOOL => "%d",
                BaseType::STRING, BaseType::PTR => "%s",
                default => throw new \RuntimeException("echo of {$value->getType()->value} unsupported"),
            };
            if ($value->getType() === BaseType::BOOL) {
                $value = $this->builder->createZext($value);
            }
            $fmtPtr = $this->builder->createStringConstant($fmt);
            $this->builder->createCall('printf', [$this->cstr($fmtPtr), $this->cstr($value)], BaseType::INT, 'i32 (ptr, ...)');
        }
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
            'Scalar_String' => $this->builder->createStringConstant($expr->string('value')),
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
        if ($var->type() !== 'Expr_Variable') {
            throw $this->error($expr, 'only simple variable assignment supported');
        }
        $value = $this->lowerExpr($expr->node('expr') ?? throw $this->error($expr));
        $name = $var->name();
        $slot = $this->scope->lookup($name);
        if ($slot === null) {
            $slot = $this->alloca($value->getType(), $name);
            $this->scope->define($name, $slot);
        }
        $this->builder->createStore($value, $slot);
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
        // ponytail: naive strcat; a runtime string concat (pico-rt equivalent) belongs here
        return $this->builder->createCall('strcat', [$lhs, $rhs], BaseType::STRING);
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

    /** Coerce a string-literal global ([N x i8]*) to a C char* (i8*). */
    private function cstr(ValueAbstract $value): ValueAbstract
    {
        if ($value instanceof \PicoIR\Global_ && $value->byteLen > 0) {
            return $this->builder->createCString($value, $value->byteLen);
        }
        return $value;
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

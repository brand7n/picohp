<?php

declare(strict_types=1);

namespace App\PicoHP\Pass\IRGen;

use App\PicoHP\{BaseType, ClassSymbol, CompilerInvariant, PicoType};
use App\PicoHP\LLVM\{Builder, ValueAbstract};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param, NullConstant, Instruction};
use App\PicoHP\SymbolTable\PicoHPData;

trait BuildExprTrait
{
    protected function isDestructuringAssign(\PhpParser\Node\Expr\Assign $expr): bool
    {
        if (!($expr->var instanceof \PhpParser\Node\Expr\Array_)) {
            return false;
        }
        // If the LHS array items are variables, it's destructuring
        foreach ($expr->var->items as $item) {
            /** @phpstan-ignore-next-line — items can be null for skipped positions */
            if ($item !== null && $item->value instanceof \PhpParser\Node\Expr\Variable) {
                return true;
            }
        }
        return false;
    }

    public function buildExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): ValueAbstract
    {
        $pData = PicoHPData::getPData($expr);
        $exprLine = $expr->getStartLine();
        if ($exprLine > 0 && $this->module->getDebugInfo()->getCurrentScope() !== null) {
            $this->builder->setDebugLine($exprLine);
        }

        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            // List/array destructuring: [$a, $b] = $arr
            if ($expr->var instanceof \PhpParser\Node\Expr\List_
                || ($expr->var instanceof \PhpParser\Node\Expr\Array_ && $this->isDestructuringAssign($expr))) {
                $arrVal = $this->buildExpr($expr->expr);
                $arrType = $this->getExprResolvedType($expr->expr);
                $items = $expr->var instanceof \PhpParser\Node\Expr\List_
                    ? $expr->var->items
                    : $expr->var->items;
                foreach ($items as $i => $item) {
                    /** @phpstan-ignore-next-line — items can be null for skipped positions */
                    if ($item !== null && $item->value !== null) {
                        $lval = $this->buildExpr($item->value);
                        $elemType = $arrType->isArray() ? $arrType->getElementBaseType() : BaseType::PTR;
                        $elemVal = $this->builder->createArrayGet($arrVal, new Constant($i, BaseType::INT), $elemType);
                        $this->builder->createStore($elemVal, $lval);
                    }
                }
                return $arrVal;
            }
            // Array literal: $arr = [1, 2, 3]
            if ($expr->expr instanceof \PhpParser\Node\Expr\Array_) {
                $lval = $this->buildExpr($expr->var);
                $arrayType = $this->getExprResolvedType($expr->var);
                $arrPtr = $this->buildArrayInit($expr->expr, $arrayType);
                $this->builder->createStore($arrPtr, $lval);
                return $arrPtr;
            }
            // Array element write: $arr[idx] = val or $arr[] = val
            if ($expr->var instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                $rval = $this->buildExpr($expr->expr);
                $arrVarExpr = $expr->var->var;
                $arrPtrPtr = $this->buildExpr($arrVarExpr);
                $arrPtr = $this->builder->createLoad($arrPtrPtr);
                $arrayType = $this->getExprResolvedType($arrVarExpr);
                $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
                if ($expr->var->dim === null) {
                    // $arr[] = val (push)
                    $this->builder->createArrayPush($arrPtr, $rval, $elemBaseType);
                } else {
                    // $arr[idx] = val (set). If idx is a STRING, treat this as a map assignment.
                    $keyOrIdxVal = $this->buildExpr($expr->var->dim);
                    $shouldUseMapSet = ($arrayType->isArray() && $arrayType->hasStringKeys())
                        || $keyOrIdxVal->getType() === BaseType::STRING;

                    if ($shouldUseMapSet) {
                        $setFunc = 'pico_map_set_' . match ($elemBaseType) {
                            BaseType::INT => 'int',
                            BaseType::FLOAT => 'float',
                            BaseType::BOOL => 'bool',
                            BaseType::STRING => 'str',
                            BaseType::PTR => 'ptr',
                            default => throw new \RuntimeException("unsupported map set type"),
                        };
                        $this->builder->createCall($setFunc, [$arrPtr, $keyOrIdxVal, $rval], BaseType::VOID);
                    } else {
                        $this->builder->createArraySet($arrPtr, $keyOrIdxVal, $rval, $elemBaseType);
                    }
                }
                return $rval;
            }
            $lval = $this->buildExpr($expr->var);
            $rval = $this->buildExpr($expr->expr);
            // Void methods used as values (e.g. $mixed = $this->voidMethod()) -> null ptr.
            // Only safe when LHS is a ptr/mixed slot; would be incorrect for typed non-ptr locals.
            if ($rval instanceof Void_) {
                $rval = new NullConstant();
            }
            $this->builder->createStore($rval, $lval);
            return $rval;
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus) {
            return $this->buildCompoundAssign($expr, 'add', 'fadd');
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Minus) {
            return $this->buildCompoundAssign($expr, 'sub', 'fsub');
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Concat) {
            // $x .= $y → $x = $x . $y
            $varPtr = $this->resolveVarPtr($expr->var);
            $lval = $this->builder->createLoad($varPtr);
            $rval = $this->buildExpr($expr->expr);
            // Coerce both sides to string for concat
            if ($lval->getType() === BaseType::INT) {
                $lval = $this->builder->createCall('pico_int_to_string', [$lval], BaseType::STRING);
            }
            if ($rval->getType() === BaseType::INT) {
                $rval = $this->builder->createCall('pico_int_to_string', [$rval], BaseType::STRING);
            }
            $result = $this->builder->createCall('pico_string_concat', [$lval, $rval], BaseType::STRING);
            $this->builder->createStore($result, $varPtr);
            return $result;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (is_string($expr->name) && $expr->name === '_SERVER') {
                CompilerInvariant::check(
                    !$pData->lVal,
                    "line {$expr->getStartLine()}, \$_SERVER cannot be assigned"
                );

                return $this->builder->createCall('pico_map_new', [], BaseType::PTR);
            }
            if (is_string($expr->name) && $expr->name === 'this') {
                CompilerInvariant::check(
                    $this->ctx->thisPtr !== null,
                    "line {$expr->getStartLine()}, \$this is unavailable outside class method context"
                );
                if ($pData->lVal) {
                    return $this->ctx->thisPtr;
                }
                return $this->builder->createLoad($this->ctx->thisPtr);
            }
            $varName = is_string($expr->name) ? $expr->name : get_debug_type($expr->name);
            CompilerInvariant::check(
                $pData->symbol !== null && $pData->symbol->value !== null,
                "line {$expr->getStartLine()}, variable \${$varName} has no allocated IR value"
            );
            $value = $pData->getValue();
            if ($pData->lVal) {
                return $value;
            }
            return $this->builder->createLoad($value);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $lval = $this->buildExpr($expr->left);
            $rval = $this->buildExpr($expr->right);
            $isNull = $this->builder->createNullCheck($lval);
            // Coerce ptr/int mismatch for nullable value types (e.g. ?int stored as ptr)
            if ($lval->getType() === BaseType::PTR && $rval->getType() !== BaseType::PTR && $rval->getType() !== BaseType::STRING) {
                $lval = $this->builder->createPtrToInt($lval);
                if ($rval->getType() === BaseType::BOOL) {
                    $truncVal = new Instruction('trunc', BaseType::BOOL);
                    $this->builder->addLine("{$truncVal->render()} = trunc i32 {$lval->render()} to i1", 1);
                    $lval = $truncVal;
                }
            }
            return $this->builder->createSelect($isNull, $rval, $lval);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $sigil = $expr->getOperatorSigil();

            // Short-circuit evaluation for && and ||
            if ($sigil === '&&' || $sigil === '||') {
                return $this->buildShortCircuit($expr, $pData);
            }

            if ($sigil === '.') {
                $lval = $this->coerceExprToStringForConcat($expr->left);
                $rval = $this->coerceExprToStringForConcat($expr->right);

                return $this->builder->createStringConcat($lval, $rval);
            }

            $lval = $this->buildExpr($expr->left);
            $rval = $this->buildExpr($expr->right);

            // For mixed-backed boxed-int values, treat ptr/string as integer bits before integer ops.
            $intOpSigils = ['|', '&', '^', '<<', '>>', '+', '-', '*', '/', '%', '<', '>', '<=', '>='];
            if (($lval->getType() === BaseType::PTR || $lval->getType() === BaseType::STRING) && in_array($sigil, $intOpSigils, true)) {
                $lval = $this->builder->createPtrToInt($lval);
            }
            if (($rval->getType() === BaseType::PTR || $rval->getType() === BaseType::STRING) && in_array($sigil, $intOpSigils, true)) {
                $rval = $this->builder->createPtrToInt($rval);
            }
            // Widen bool to int for integer operations
            if ($lval->getType() === BaseType::BOOL && in_array($sigil, $intOpSigils, true)) {
                $lval = $this->builder->createZext($lval);
            }
            if ($rval->getType() === BaseType::BOOL && in_array($sigil, $intOpSigils, true)) {
                $rval = $this->builder->createZext($rval);
            }

            // Different types with === / !== — result is known at compile time
            if ($lval->getType() !== $rval->getType() && ($sigil === '===' || $sigil === '!==')) {
                return new Constant($sigil === '!==' ? 1 : 0, BaseType::BOOL);
            }

            $isFloat = $lval->getType() === BaseType::FLOAT;
            $operandType = $lval->getType();
            switch ($sigil) {
                case '+':
                    $val = $this->builder->createInstruction($isFloat ? 'fadd' : 'add', [$lval, $rval], resultType: $operandType);
                    break;
                case '*':
                    $val = $this->builder->createInstruction($isFloat ? 'fmul' : 'mul', [$lval, $rval], resultType: $operandType);
                    break;
                case '-':
                    $val = $this->builder->createInstruction($isFloat ? 'fsub' : 'sub', [$lval, $rval], resultType: $operandType);
                    break;
                case '/':
                    $val = $this->builder->createInstruction($isFloat ? 'fdiv' : 'sdiv', [$lval, $rval], resultType: $operandType);
                    break;
                case '%':
                    $val = $this->builder->createInstruction($isFloat ? 'frem' : 'srem', [$lval, $rval], resultType: $operandType);
                    break;
                case '&':
                    $val = $this->builder->createInstruction('and', [$lval, $rval]);
                    break;
                case '|':
                    $val = $this->builder->createInstruction('or', [$lval, $rval]);
                    break;
                case '<<':
                    $val = $this->builder->createInstruction('shl', [$lval, $rval]);
                    break;
                case '>>':
                    $val = $this->builder->createInstruction('ashr', [$lval, $rval]);
                    break;
                case '==':
                case '===':
                    if ($operandType === BaseType::STRING
                        && !($lval instanceof NullConstant)
                        && !($rval instanceof NullConstant)
                    ) {
                        $result = $this->builder->createCall('pico_string_eq', [$lval, $rval], BaseType::INT);
                        $val = $this->builder->createInstruction(
                            'icmp ne',
                            [$result, new Constant(0, BaseType::INT)],
                            resultType: BaseType::BOOL,
                        );
                        break;
                    }
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp oeq' : 'icmp eq', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '!=':
                case '!==':
                    if ($operandType === BaseType::STRING
                        && !($lval instanceof NullConstant)
                        && !($rval instanceof NullConstant)
                    ) {
                        $result = $this->builder->createCall('pico_string_ne', [$lval, $rval], BaseType::INT);
                        $val = $this->builder->createInstruction(
                            'icmp ne',
                            [$result, new Constant(0, BaseType::INT)],
                            resultType: BaseType::BOOL,
                        );
                        break;
                    }
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp one' : 'icmp ne', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '<':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp olt' : 'icmp slt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '>':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp ogt' : 'icmp sgt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '<=':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp ole' : 'icmp sle', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '>=':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp oge' : 'icmp sge', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$sigil}");
            }
            return $val;
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            return $this->builder->createInstruction('sub', [new Constant(0, BaseType::INT), $this->buildExpr($expr->expr)]);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, BaseType::INT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, BaseType::FLOAT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $this->builder->createStringConstant($expr->value);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\MagicConst\Dir) {
            $path = $expr->getAttribute('pico_source_file');
            CompilerInvariant::check(is_string($path) && $path !== '', 'Scalar\\MagicConst\\Dir requires pico_source_file on the AST (from BuildCommand)');

            return $this->builder->createStringConstant(dirname($path));
        } elseif ($expr instanceof \PhpParser\Node\Scalar\MagicConst\File) {
            $path = $expr->getAttribute('pico_source_file');
            CompilerInvariant::check(is_string($path) && $path !== '', 'Scalar\\MagicConst\\File requires pico_source_file on the AST (from BuildCommand)');

            return $this->builder->createStringConstant($path);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) {

                } else {
                    return $this->buildExpr($part);
                }
            }
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $caseName = $expr->name->toString();
            if ($caseName === 'class') {
                return $this->builder->createStringConstant($className);
            }
            if (isset($this->enumRegistry[$className])) {
                $tag = $this->enumRegistry[$className]->getCaseTag($caseName);
                return new Constant($tag, BaseType::INT);
            }
            if (isset($this->classRegistry[$className])) {
                if (isset($this->classRegistry[$className]->constants[$caseName])) {
                    return new Constant($this->classRegistry[$className]->constants[$caseName], BaseType::INT);
                }
                // Unknown constant on a known class — return 0 (stub behavior)
                return new Constant(0, BaseType::INT);
            }
            throw new \RuntimeException("class constant {$className}::{$caseName} not supported");
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $expr->name->toLowerString();
            if ($constName === 'null') {
                return new NullConstant();
            }
            if ($constName === 'true') {
                return new Constant(1, BaseType::BOOL);
            }
            if ($constName === 'false') {
                return new Constant(0, BaseType::BOOL);
            }
            if ($constName === 'stdin') {
                return new Constant(0, BaseType::INT);
            }
            if ($constName === 'stdout') {
                return new Constant(1, BaseType::INT);
            }
            if ($constName === 'stderr') {
                return new Constant(2, BaseType::INT);
            }
            if ($constName === 'debug_backtrace_ignore_args') {
                return new Constant(2, BaseType::INT);
            }
            if ($constName === 'debug_backtrace_provide_object') {
                return new Constant(1, BaseType::INT);
            }
            if ($constName === 'directory_separator') {
                return $this->builder->createStringConstant(DIRECTORY_SEPARATOR);
            }
            // PHP tokenizer constants — resolve at compile time
            if (str_starts_with($constName, 't_') && defined(strtoupper($constName))) {
                /** @var int $val */
                $val = constant(strtoupper($constName));
                return new Constant($val, BaseType::INT);
            }
            return new Constant($constName === 'true' ? 1 : 0, BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $val;
                case BaseType::FLOAT:
                    return $this->builder->createFpToSi($val);
                case BaseType::BOOL:
                    return $this->builder->createZext($val);
                default:
                    throw new \Exception("casting to int from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createSiToFp($val);
                case BaseType::FLOAT:
                    return $val;
                case BaseType::BOOL:
                    return $this->builder->createSiToFp($this->builder->createZext($val));
                default:
                    throw new \Exception("casting to float from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Bool_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createInstruction('icmp ne', [$val, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
                case BaseType::FLOAT:
                    return $this->builder->createInstruction('fcmp one', [$val, new Constant(0.0, BaseType::FLOAT)], resultType: BaseType::BOOL);
                case BaseType::BOOL:
                    return $val;
                case BaseType::PTR:
                case BaseType::STRING:
                    return $this->builder->createInstruction('icmp ne', [$val, new NullConstant()], resultType: BaseType::BOOL);
                default:
                    throw new \Exception("casting to bool from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\String_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createCall('pico_int_to_string', [$val], BaseType::STRING);
                case BaseType::FLOAT:
                    return $this->builder->createCall('pico_float_to_string', [$val], BaseType::STRING);
                case BaseType::STRING:
                    return $val;
                default:
                    throw new \Exception("casting to string from unsupported type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            // Built-in functions
            if ($funcName === 'assert') {
                // Compile assert as no-op (assertions stripped in compiled code)
                return new Void_();
            }
            if ($funcName === 'class_alias') {
                return new Void_();
            }
            if ($funcName === 'class_exists') {
                // All classes are statically known at compile time
                return new Constant(1, BaseType::BOOL);
            }
            if ($funcName === 'count') {
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createArrayLen($arrVal);
            }
            if ($funcName === 'strval') {
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                if ($val->getType() === BaseType::FLOAT) {
                    return $this->builder->createCall('pico_float_to_string', [$val], BaseType::STRING);
                }
                return $this->builder->createCall('pico_int_to_string', [$val], BaseType::STRING);
            }
            if ($funcName === 'max') {
                CompilerInvariant::check(count($expr->args) === 2);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg && $expr->args[1] instanceof \PhpParser\Node\Arg);
                $a = $this->buildExpr($expr->args[0]->value);
                $b = $this->buildExpr($expr->args[1]->value);
                CompilerInvariant::check($a->getType() === BaseType::INT && $b->getType() === BaseType::INT);
                $cmp = $this->builder->createInstruction('icmp sgt', [$a, $b], resultType: BaseType::BOOL);

                return $this->builder->createSelect($cmp, $a, $b);
            }
            if ($funcName === 'fwrite') {
                CompilerInvariant::check(count($expr->args) >= 2 && count($expr->args) <= 3);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg && $expr->args[1] instanceof \PhpParser\Node\Arg);
                $fd = $this->buildExpr($expr->args[0]->value);
                $data = $this->buildExpr($expr->args[1]->value);
                // Coerce ptr/mixed fd to int (vendor code may pass resource-typed values)
                if ($fd->getType() !== BaseType::INT) {
                    $fd = $this->builder->createPtrToInt($fd);
                }
                if ($data->getType() !== BaseType::STRING && $data->getType() !== BaseType::PTR) {
                    $data = $this->builder->createCall('pico_int_to_string', [$data], BaseType::STRING);
                }
                if (count($expr->args) === 3 && $expr->args[2] instanceof \PhpParser\Node\Arg) {
                    $length = $this->buildExpr($expr->args[2]->value);

                    return $this->builder->createCall('pico_fwrite', [$fd, $data, $length], BaseType::INT);
                }

                return $this->builder->createCall('pico_fwrite', [$fd, $data, new Constant(-1, BaseType::INT)], BaseType::INT);
            }
            if ($funcName === 'debug_backtrace') {
                foreach ($expr->args as $arg) {
                    CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                    $this->buildExpr($arg->value);
                }

                return $this->builder->createArrayNew();
            }

            if ($funcName === 'array_key_exists') {
                CompilerInvariant::check(count($expr->args) === 2);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $key = $this->buildExpr($expr->args[0]->value);
                $map = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_map_has_key', [$map, $key], BaseType::BOOL);
            }
            if ($funcName === 'array_search') {
                CompilerInvariant::check(count($expr->args) >= 2);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $needle = $this->buildExpr($expr->args[0]->value);
                $haystack = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_array_search_int', [$haystack, $needle], BaseType::INT);
            }
            if ($funcName === 'array_reverse') {
                CompilerInvariant::check(count($expr->args) >= 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->buildExpr($expr->args[0]->value);
            }
            if ($funcName === 'array_pop') {
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrPtr = $this->buildExpr($expr->args[0]->value);
                $len = $this->builder->createArrayLen($arrPtr);
                $lastIdx = $this->builder->createInstruction('sub', [$len, new Constant(1, BaseType::INT)]);
                $this->builder->createCall('pico_array_splice', [$arrPtr, $lastIdx, new Constant(1, BaseType::INT)], BaseType::VOID);
                return new Void_();
            }
            if ($funcName === 'array_merge') {
                CompilerInvariant::check(count($expr->args) >= 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->buildExpr($expr->args[0]->value);
            }
            if ($funcName === 'end') {
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrPtr = $this->buildExpr($expr->args[0]->value);
                $arrType = $this->getExprResolvedType($expr->args[0]->value);
                if ($arrType->getElementBaseType() === BaseType::STRING) {
                    return $this->builder->createCall('pico_array_last_str', [$arrPtr], BaseType::STRING);
                }
                return $this->builder->createCall('pico_array_last_int', [$arrPtr], BaseType::INT);
            }
            if ($funcName === 'preg_match') {
                CompilerInvariant::check(count($expr->args) >= 2);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $pattern = $this->buildExpr($expr->args[0]->value);
                $subject = $this->buildExpr($expr->args[1]->value);
                if (count($expr->args) >= 3 && $expr->args[2] instanceof \PhpParser\Node\Arg) {
                    // 3rd arg is by-reference matches array — load the array ptr
                    $matchesPtr = $this->buildExpr($expr->args[2]->value);
                    return $this->builder->createCall('pico_preg_match', [$pattern, $subject, $matchesPtr], BaseType::INT);
                }
                // No matches arg — create a temp array and discard
                $tmpArr = $this->builder->createArrayNew();
                return $this->builder->createCall('pico_preg_match', [$pattern, $subject, $tmpArr], BaseType::INT);
            }
            if ($funcName === 'is_int' || $funcName === 'is_string' || $funcName === 'is_float' || $funcName === 'is_bool') {
                // At compile time we know the type
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                $expected = match ($funcName) {
                    'is_int' => BaseType::INT,
                    'is_string' => BaseType::STRING,
                    'is_float' => BaseType::FLOAT,
                    'is_bool' => BaseType::BOOL,
                };
                return new Constant($val->getType() === $expected ? 1 : 0, BaseType::BOOL);
            }
            if ($funcName === 'intval') {
                CompilerInvariant::check(count($expr->args) === 1);
                CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                if ($val->getType() === BaseType::FLOAT) {
                    return $this->builder->createFpToSi($val);
                }
                return $val;
            }
            // Registry-driven builtin codegen: call runtime symbol with args
            if ($this->builtinRegistry->has($funcName)) {
                $def = $this->builtinRegistry->get($funcName);
                if ($def->runtimeSymbol !== null && $def->intrinsic === null) {
                    $funcSymbol = $pData->getSymbol();
                    $args = $this->buildArgsWithDefaults($expr->args, $funcSymbol);
                    $retBase = $def->returnBaseType();
                    // Runtime returns i32 for bool functions — coerce to i1
                    if ($retBase === BaseType::BOOL) {
                        $result = $this->builder->createCall($def->runtimeSymbol, $args, BaseType::INT);
                        return $this->builder->createInstruction('icmp ne', [$result, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
                    }
                    return $this->builder->createCall($def->runtimeSymbol, $args, $retBase);
                }
            }
            $funcSymbol = $pData->getSymbol();
            // Stub functions (unknown builtins) — throw "unimplemented" exception
            if ($funcSymbol->type->isMixed() && !$this->module->hasFunction($expr->name->name)) {
                // Build args for side effects
                foreach ($expr->args as $arg) {
                    CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                    $this->buildExpr($arg->value);
                }
                return $this->emitUnimplementedThrow($expr->name->name);
            }
            $args = $this->buildArgsWithDefaults($expr->args, $funcSymbol);
            $returnType = $funcSymbol->type->toBase();

            if ($funcSymbol->canThrow) {
                return $this->emitThrowingCall($expr->name->name, $args, $returnType, $pData);
            }

            return $this->builder->createCall($expr->name->name, $args, $returnType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            if ($expr->var instanceof \PhpParser\Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->var->name === '_SERVER'
            ) {
                CompilerInvariant::check($expr->dim !== null, "line {$expr->getStartLine()}, \$_SERVER[...] requires index");

                return new NullConstant(BaseType::PTR);
            }
            $varType = $this->getExprResolvedType($expr->var);
            if ($varType->isArray() || $varType->isMixed()) {
                CompilerInvariant::check($expr->dim !== null, "array read requires index");
                $arrPtr = $this->buildExpr($expr->var);
                $idx = $this->buildExpr($expr->dim);
                $elemBaseType = $varType->isMixed() ? BaseType::PTR : $varType->getElementBaseType();
                if (($varType->isArray() && $varType->hasStringKeys()) || $idx->getType() === BaseType::STRING) {
                    $getFunc = 'pico_map_get_' . match ($elemBaseType) {
                        BaseType::INT => 'int',
                        BaseType::FLOAT => 'float',
                        BaseType::BOOL => 'bool',
                        BaseType::STRING => 'str',
                        BaseType::PTR => 'ptr',
                        default => throw new \RuntimeException("unsupported map get type"),
                    };
                    return $this->builder->createCall($getFunc, [$arrPtr, $idx], $elemBaseType);
                }
                return $this->builder->createArrayGet($arrPtr, $idx, $elemBaseType);
            }
            // String indexing (existing behavior)
            CompilerInvariant::check($expr->dim !== null);
            CompilerInvariant::check(
                $pData->lVal !== true,
                "line {$expr->getStartLine()}, string index assignment is not supported"
            );
            $strVal = $this->buildExpr($expr->var);
            $idx = $this->buildExpr($expr->dim);
            return $this->builder->createStringByteAt($strVal, $idx);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Include_) {
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostInc) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostDec) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\BooleanNot) {
            $val = $this->buildExpr($expr->expr);
            // For ptr/mixed values, !$val means $val == null (i.e. falsy)
            if ($val->getType() === BaseType::PTR || $val->getType() === BaseType::STRING) {
                return $this->builder->createInstruction('icmp eq', [$val, new NullConstant()], resultType: BaseType::BOOL);
            }
            return $this->builder->createInstruction('xor', [$val, new Constant(1, BaseType::BOOL)], resultType: BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreInc) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreDec) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\New_) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self' || $rawClass === 'static') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $classMeta = $this->classRegistry[$className];
            $typeId = $this->typeIdMap[$className] ?? 0;
            $llvmStruct = ClassSymbol::mangle($className);
            $objPtr = $this->builder->createObjectAlloc($llvmStruct, $typeId);
            // Store type_id in field 0
            $typeIdPtr = $this->builder->createStructGEP($llvmStruct, $objPtr, 0, BaseType::INT);
            $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
            // Emit property default values before constructor
            $this->emitPropertyDefaults($className, $classMeta, $objPtr);
            // Call constructor if it exists
            if (isset($classMeta->methods['__construct'])) {
                $ctorSymbol = $classMeta->methods['__construct'];
                $args = $this->buildArgsWithDefaults($expr->args, $ctorSymbol);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$objPtr], $args);
                $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                $qualifiedName = ClassSymbol::llvmMethodSymbol($ctorOwner, '__construct');
                $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
            }
            return $objPtr;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            // Mixed type: no static class name, emit null ptr (stub behavior)
            if ($varType->isMixed()) {
                return new NullConstant();
            }
            // Enum ->value access — look up backing value from global table by tag index
            if ($varType->isEnum() && $expr->name->toString() === 'value') {
                $enumName = $varType->getClassName();
                $enumMeta = $this->enumRegistry[$enumName];
                $llvmEnum = ClassSymbol::mangle($enumName);
                $count = count($enumMeta->cases);
                if ($enumMeta->backingType === 'string') {
                    $elemPtr = $this->builder->createEnumValueLookup($llvmEnum, $count, $objVal);
                    return $this->builder->createLoad($elemPtr);
                }
                // int-backed: GEP into i32 values array
                $elemPtr = new \App\PicoHP\LLVM\Value\Instruction('enum_val', BaseType::INT);
                $this->builder->addLine("{$elemPtr->render()} = getelementptr inbounds [{$count} x i32], ptr @{$llvmEnum}_values, i32 0, i32 {$objVal->render()}", 1);
                return $this->builder->createLoad($elemPtr);
            }
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            // Virtual dispatch when the property isn't on this class (interface or abstract base)
            if (!isset($classMeta->properties[$propName])) {
                return $this->emitVirtualPropertyDispatch($objVal, $className, $propName, $pData->lVal);
            }
            $fieldIndex = $classMeta->getPropertyIndex($propName);
            $fieldType = $classMeta->getPropertyType($propName)->toBase();
            $fieldPtr = $this->builder->createStructGEP(ClassSymbol::mangle($className), $objVal, $fieldIndex, $fieldType);
            if ($pData->lVal) {
                return $fieldPtr;
            }
            return $this->builder->createLoad($fieldPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            // Mixed type: no static class name, emit null ptr (stub behavior)
            if ($varType->isMixed()) {
                // Still need to build args for side effects
                foreach ($expr->args as $arg) {
                    CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                    $this->buildExpr($arg->value);
                }
                return new NullConstant();
            }
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $methodName = $expr->name->toString();
            $methodSymbol = $classMeta->methods[$methodName];
            $args = $this->buildArgsWithDefaults($expr->args, $methodSymbol);
            /** @var array<ValueAbstract> $allArgs */
            $allArgs = array_merge([$objVal], $args);
            $returnType = $methodSymbol->type->toBase();

            // Virtual dispatch: interface (no type_id) or abstract method
            if (!isset($this->typeIdMap[$className]) || $this->needsVirtualDispatch($className, $methodName)) {
                return $this->emitVirtualDispatch($objVal, $className, $methodName, $allArgs, $returnType);
            }

            $ownerClass = $classMeta->methodOwner[$methodName] ?? $className;
            $qualifiedName = ClassSymbol::llvmMethodSymbol($ownerClass, $methodName);
            return $this->builder->createCall($qualifiedName, $allArgs, $returnType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $rawClass = $expr->class->toString();
            $methodName = $expr->name->toString();
            if ($rawClass === 'self') {
                CompilerInvariant::check($this->ctx->className !== null);
                $targetClass = $this->ctx->className;
            } elseif ($rawClass === 'parent') {
                $targetClass = 'parent';
            } else {
                $targetClass = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            if ($targetClass === 'parent') {
                CompilerInvariant::check($this->ctx->className !== null);
                $classMeta = $this->classRegistry[$this->ctx->className];
                CompilerInvariant::check($classMeta->parentName !== null);
                $parentMeta = $this->classRegistry[$classMeta->parentName];
                $methodSymbol = $parentMeta->methods[$methodName];
                $ownerClass = $parentMeta->methodOwner[$methodName] ?? $classMeta->parentName;
                $targetClass = $ownerClass;
            } else {
                $classMeta = $this->classRegistry[$targetClass];
                $methodSymbol = $classMeta->methods[$methodName];
            }
            $args = [];
            foreach ($expr->args as $arg) {
                CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                $args[] = $this->buildExpr($arg->value);
            }
            // Pass $this as first argument for parent:: calls
            if ($expr->class->toString() === 'parent') {
                // Load $this from param 0 alloca
                CompilerInvariant::check($this->ctx->thisPtr !== null);
                $thisVal = $this->builder->createLoad($this->ctx->thisPtr);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$thisVal], $args);
            } else {
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = $args;
            }
            $qualifiedName = ClassSymbol::llvmMethodSymbol($targetClass, $methodName);
            return $this->builder->createCall($qualifiedName, $allArgs, $methodSymbol->type->toBase());
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self' || $rawClass === 'static') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $propName = $expr->name->toString();
            $classMeta = $this->classRegistry[$className];
            $propType = $classMeta->staticProperties[$propName];
            $globalName = ClassSymbol::mangle($className) . '_' . $propName;
            $globalPtr = new \App\PicoHP\LLVM\Value\Global_($globalName, $propType->toBase());
            if ($pData->lVal) {
                return $globalPtr;
            }
            return $this->builder->createLoad($globalPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Match_) {
            CompilerInvariant::check($this->ctx->function !== null);
            $count = $pData->mycount;
            $condVal = $this->buildExpr($expr->cond);
            $condType = $condVal->getType();

            $currentFunc = $this->ctx->function;
            $endBlock = $currentFunc->addBasicBlock("match_end{$count}");
            $endLabel = new Label($endBlock->getName());

            // Separate default arm from conditional arms
            $defaultArm = null;
            $conditionalArms = [];
            foreach ($expr->arms as $arm) {
                if ($arm->conds === null) {
                    $defaultArm = $arm;
                } else {
                    $conditionalArms[] = $arm;
                }
            }

            // Create blocks for each arm body and next-check
            $armBlocks = [];
            $nextBlocks = [];
            foreach ($conditionalArms as $i => $arm) {
                $armBlocks[] = $currentFunc->addBasicBlock("match_arm{$count}_{$i}");
                // Only create next-check blocks for non-last arms
                if ($i + 1 < count($conditionalArms)) {
                    $nextBlocks[] = $currentFunc->addBasicBlock("match_next{$count}_{$i}");
                }
            }
            $defaultBlock = $defaultArm !== null
                ? $currentFunc->addBasicBlock("match_default{$count}")
                : $endBlock;

            // Determine result type from first arm body, allocate result
            $firstBody = $defaultArm !== null ? $defaultArm->body : $conditionalArms[0]->body;
            $firstBodyType = $this->resolveMatchArmType($firstBody);
            $resultPtr = $this->builder->createAlloca("match_result{$count}", $firstBodyType);

            // Emit condition checks and arm bodies
            foreach ($conditionalArms as $i => $arm) {
                CompilerInvariant::check($arm->conds !== null);

                if (count($arm->conds) === 1) {
                    $armCondVal = $this->buildExpr($arm->conds[0]);
                    $cmpLeft = $condVal;
                    $cmpType = $condType;
                    // Coerce type mismatches in nullable enum/ptr matches
                    if ($cmpType === BaseType::PTR && $armCondVal->getType() === BaseType::INT) {
                        $cmpLeft = $this->builder->createPtrToInt($condVal);
                        $cmpType = BaseType::INT;
                    } elseif ($cmpType === BaseType::INT && ($armCondVal->getType() === BaseType::PTR || $armCondVal->getType() === BaseType::STRING)) {
                        $armCondVal = $this->builder->createPtrToInt($armCondVal);
                    }
                    $isFloat = $cmpType === BaseType::FLOAT;
                    $cmpResult = $this->builder->createInstruction(
                        $isFloat ? 'fcmp oeq' : 'icmp eq',
                        [$cmpLeft, $armCondVal],
                        resultType: BaseType::BOOL
                    );
                } else {
                    // Multiple conditions: OR them together
                    $orResult = null;
                    foreach ($arm->conds as $armCond) {
                        $armCondVal = $this->buildExpr($armCond);
                        $isFloat = $condType === BaseType::FLOAT;
                        $cmpResult = $this->builder->createInstruction(
                            $isFloat ? 'fcmp oeq' : 'icmp eq',
                            [$condVal, $armCondVal],
                            resultType: BaseType::BOOL
                        );
                        if ($orResult === null) {
                            $orResult = $cmpResult;
                        } else {
                            $orResult = $this->builder->createInstruction('or', [$orResult, $cmpResult], resultType: BaseType::BOOL);
                        }
                    }
                    $cmpResult = $orResult;
                    CompilerInvariant::check($cmpResult !== null);
                }

                $armLabel = new Label($armBlocks[$i]->getName());
                if ($i + 1 < count($conditionalArms)) {
                    $fallthrough = new Label($nextBlocks[$i]->getName());
                } else {
                    $fallthrough = new Label($defaultBlock->getName());
                }
                $this->builder->createBranch([$cmpResult, $armLabel, $fallthrough]);

                // Emit arm body
                $this->builder->setInsertPoint($armBlocks[$i]);
                $bodyVal = $this->buildExpr($arm->body);
                $this->builder->createStore($bodyVal, $resultPtr);
                $this->builder->createBranch([$endLabel]);

                // Set insert point to next check block
                if ($i + 1 < count($conditionalArms)) {
                    $this->builder->setInsertPoint($nextBlocks[$i]);
                }
            }

            // Emit default arm
            if ($defaultArm !== null) {
                $this->builder->setInsertPoint($defaultBlock);
                $bodyVal = $this->buildExpr($defaultArm->body);
                $this->builder->createStore($bodyVal, $resultPtr);
                $this->builder->createBranch([$endLabel]);
            }

            // Continue from end block
            $this->builder->setInsertPoint($endBlock);
            return $this->builder->createLoad($resultPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Ternary) {
            CompilerInvariant::check($this->ctx->function !== null);
            $count = $pData->mycount;

            $condVal = $this->coerceToBool($this->buildExpr($expr->cond));

            $thenBB = $this->ctx->function->addBasicBlock("ternary_then{$count}");
            $elseBB = $this->ctx->function->addBasicBlock("ternary_else{$count}");
            $endBB = $this->ctx->function->addBasicBlock("ternary_end{$count}");
            $this->builder->createBranch([$condVal, new Label($thenBB->getName()), new Label($elseBB->getName())]);

            $this->builder->setInsertPoint($thenBB);
            $thenVal = $this->buildExpr($expr->if ?? $expr->cond);
            $resultPtr = $this->builder->createEntryAlloca($this->ctx->function, "ternary_result{$count}", $thenVal->getType());
            $this->builder->createStore($thenVal, $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);

            $this->builder->setInsertPoint($elseBB);
            $elseVal = $this->buildExpr($expr->else);
            // Coerce else to match then type if they differ
            $resultType = $thenVal->getType();
            if ($elseVal->getType() !== $resultType) {
                if ($resultType === BaseType::INT && ($elseVal->getType() === BaseType::PTR || $elseVal->getType() === BaseType::STRING)) {
                    $elseVal = $this->builder->createPtrToInt($elseVal);
                } elseif (($resultType === BaseType::PTR || $resultType === BaseType::STRING) && $elseVal->getType() === BaseType::INT) {
                    $castVal = new Instruction('inttoptr', BaseType::PTR);
                    $this->builder->addLine("{$castVal->render()} = inttoptr i32 {$elseVal->render()} to ptr", 1);
                    $elseVal = $castVal;
                }
            }
            $this->builder->createStore($elseVal, $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);

            $this->builder->setInsertPoint($endBB);
            return $this->builder->createLoad($resultPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Isset_) {
            // isset($x) on nullable ptr: check if not null; non-ptr types are always set
            CompilerInvariant::check(count($expr->vars) === 1);
            $val = $this->buildExpr($expr->vars[0]);
            if ($val->getType() !== BaseType::PTR && $val->getType() !== BaseType::STRING) {
                return new Constant(1, BaseType::BOOL);
            }
            return $this->builder->createInstruction('icmp ne', [$val, new NullConstant()], resultType: BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Instanceof_) {
            $objVal = $this->buildExpr($expr->expr);
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $targetClass = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());

            // Load runtime type_id from field 0 using the static type for GEP
            // All class structs share i32 type_id at index 0 by convention
            $staticType = $this->getExprResolvedType($expr->expr);
            // For non-object types (e.g. mixed), fall back to the RHS class name for GEP
            $gepClass = $staticType->isObject() ? $staticType->getClassName() : $targetClass;
            // For interface/abstract types without a concrete struct, use first descendant
            if (!isset($this->typeIdMap[$gepClass])) {
                $descendants = $this->findDescendants($gepClass);
                CompilerInvariant::check(count($descendants) > 0, "no concrete types for instanceof {$targetClass}");
                $gepClass = $descendants[0];
            }
            $typeIdPtr = $this->builder->createStructGEP(ClassSymbol::mangle($gepClass), $objVal, 0, BaseType::INT);
            $typeIdVal = $this->builder->createLoad($typeIdPtr);

            // Collect all type_ids that match: the target class + all concrete descendants
            $matchIds = [];
            if (isset($this->typeIdMap[$targetClass])) {
                $matchIds[] = $this->typeIdMap[$targetClass];
            }
            foreach ($this->findDescendants($targetClass) as $desc) {
                if (isset($this->typeIdMap[$desc])) {
                    $matchIds[] = $this->typeIdMap[$desc];
                }
            }

            $matchIds = array_values(array_unique($matchIds));
            if (count($matchIds) === 0) {
                return new Constant(0, BaseType::BOOL);
            }
            if (count($matchIds) === 1) {
                return $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[0], BaseType::INT)], resultType: BaseType::BOOL);
            }
            // Multiple targets: OR chain
            $result = $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[0], BaseType::INT)], resultType: BaseType::BOOL);
            for ($i = 1; $i < count($matchIds); $i++) {
                $cmp = $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[$i], BaseType::INT)], resultType: BaseType::BOOL);
                $result = $this->builder->createInstruction('or', [$result, $cmp], resultType: BaseType::BOOL);
            }
            return $result;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Exit_) {
            $exitCode = new Constant(0, BaseType::INT);
            if ($expr->expr !== null) {
                $val = $this->buildExpr($expr->expr);
                if ($val->getType() === BaseType::INT) {
                    $exitCode = $val;
                }
            }
            $this->builder->addLine("call void @exit(i32 {$exitCode->render()})", 1);
            $this->builder->addLine('unreachable', 1);
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\Throw_) {
            if (!($expr->expr instanceof \PhpParser\Node\Expr\New_)) {
                // throw $variable — re-throw an existing exception
                $objPtr = $this->buildExpr($expr->expr);
                if ($this->ctx->tryContext !== null) {
                    $this->builder->createStore($objPtr, $this->ctx->tryContext['exceptionSlot']);
                    $this->builder->createBranch([$this->ctx->tryContext['catchLabel']]);
                } elseif ($this->ctx->function !== null && $this->ctx->function->canThrow) {
                    $retType = $this->ctx->function->getReturnType()->toBase();
                    $errResult = $this->builder->createResultErr($objPtr, $retType);
                    $structType = Builder::resultTypeName($retType);
                    $this->builder->addLine("ret {$structType} {$errResult->render()}", 1);
                } else {
                    $this->builder->addLine('call void @abort()', 1);
                    $this->builder->addLine('unreachable', 1);
                }
                return new Void_();
            }
            $newExpr = $expr->expr;
            CompilerInvariant::check($newExpr->class instanceof \PhpParser\Node\Name);
            $className = ClassSymbol::fqcnFromResolvedName($newExpr->class, $this->currentNamespace());
            $classMeta = $this->classRegistry[$className];
            $typeId = $this->typeIdMap[$className] ?? 0;
            $llvmStruct = ClassSymbol::mangle($className);
            $objPtr = $this->builder->createObjectAlloc($llvmStruct, $typeId);
            // Store type_id in field 0
            $typeIdPtr = $this->builder->createStructGEP($llvmStruct, $objPtr, 0, BaseType::INT);
            $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
            // Call constructor if it exists
            if (isset($classMeta->methods['__construct'])) {
                $ctorSymbol = $classMeta->methods['__construct'];
                $args = $this->buildArgsWithDefaults($newExpr->args, $ctorSymbol);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$objPtr], $args);
                $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                $qualifiedName = ClassSymbol::llvmMethodSymbol($ctorOwner, '__construct');
                $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
            }
            // Dispatch the exception
            if ($this->ctx->tryContext !== null) {
                // Inside a try block — store exception and branch to catch dispatch
                $this->builder->createStore($objPtr, $this->ctx->tryContext['exceptionSlot']);
                $this->builder->createBranch([$this->ctx->tryContext['catchLabel']]);
            } elseif ($this->ctx->function !== null && $this->ctx->function->canThrow) {
                // In a throwing function — return error result
                $retType = $this->ctx->function->getReturnType()->toBase();
                $errResult = $this->builder->createResultErr($objPtr, $retType);
                $structType = Builder::resultTypeName($retType);
                $this->builder->addLine("ret {$structType} {$errResult->render()}", 1);
            } else {
                // Uncaught — abort
                $this->builder->addLine('call void @abort()', 1);
                $this->builder->addLine('unreachable', 1);
            }
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\Array_) {
            // Array literal as standalone expression (e.g. return [])
            return $this->builder->createArrayNew();
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
        }
    }

    /**
     * @param 'add'|'sub' $intOpcode
     * @param 'fadd'|'fsub' $floatOpcode
     */
    protected function buildCompoundAssign(
        \PhpParser\Node\Expr\AssignOp\Plus|\PhpParser\Node\Expr\AssignOp\Minus $expr,
        string $intOpcode,
        string $floatOpcode
    ): ValueAbstract {
        $lhs = $expr->var;
        if ($lhs instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            CompilerInvariant::check($lhs->dim !== null);
            $innerType = $this->getExprResolvedType($lhs->var);
            CompilerInvariant::check(
                $innerType->isArray() || $innerType->isMixed(),
                "line {$lhs->getStartLine()}, compound assignment on this target is not supported"
            );
            $arrVarExpr = $lhs->var;
            $arrPtrPtr = $this->buildExpr($arrVarExpr);
            $arrPtr = $this->builder->createLoad($arrPtrPtr);
            $arrayType = $this->getExprResolvedType($arrVarExpr);
            $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
            $keyOrIdxVal = $this->buildExpr($lhs->dim);
            $shouldUseMapSet = ($arrayType->isArray() && $arrayType->hasStringKeys())
                || $keyOrIdxVal->getType() === BaseType::STRING;

            if ($shouldUseMapSet) {
                $getFunc = 'pico_map_get_' . match ($elemBaseType) {
                    BaseType::INT => 'int',
                    BaseType::FLOAT => 'float',
                    BaseType::BOOL => 'bool',
                    BaseType::STRING => 'str',
                    BaseType::PTR => 'ptr',
                    default => throw new \RuntimeException('unsupported map get type'),
                };
                $oldVal = $this->builder->createCall($getFunc, [$arrPtr, $keyOrIdxVal], $elemBaseType);
            } else {
                $oldVal = $this->builder->createArrayGet($arrPtr, $keyOrIdxVal, $elemBaseType);
            }

            $rhs = $this->buildExpr($expr->expr);
            $newVal = $this->buildArithmeticBinResult($oldVal, $rhs, $intOpcode, $floatOpcode);

            if ($shouldUseMapSet) {
                $setFunc = 'pico_map_set_' . match ($elemBaseType) {
                    BaseType::INT => 'int',
                    BaseType::FLOAT => 'float',
                    BaseType::BOOL => 'bool',
                    BaseType::STRING => 'str',
                    BaseType::PTR => 'ptr',
                    default => throw new \RuntimeException('unsupported map set type'),
                };
                $this->builder->createCall($setFunc, [$arrPtr, $keyOrIdxVal, $newVal], BaseType::VOID);
            } else {
                $this->builder->createArraySet($arrPtr, $keyOrIdxVal, $newVal, $elemBaseType);
            }

            return $newVal;
        }

        $ptr = $this->buildExpr($lhs);
        $oldVal = $this->builder->createLoad($ptr);
        $rhs = $this->buildExpr($expr->expr);
        $newVal = $this->buildArithmeticBinResult($oldVal, $rhs, $intOpcode, $floatOpcode);
        $this->builder->createStore($newVal, $ptr);

        return $newVal;
    }

    /**
     * Integer/float add or sub, mirroring {@see buildExpr} binary op handling for `+` / `-`.
     *
     * @param 'add'|'sub' $intOpcode
     * @param 'fadd'|'fsub' $floatOpcode
     */
    protected function buildArithmeticBinResult(
        ValueAbstract $lval,
        ValueAbstract $rval,
        string $intOpcode,
        string $floatOpcode
    ): ValueAbstract {
        // Match BinaryOp `+` / `-` handling for mixed/ptr-backed values.
        if ($lval->getType() === BaseType::PTR || $lval->getType() === BaseType::STRING) {
            $lval = $this->builder->createPtrToInt($lval);
        }
        if ($rval->getType() === BaseType::PTR || $rval->getType() === BaseType::STRING) {
            $rval = $this->builder->createPtrToInt($rval);
        }

        $isFloat = $lval->getType() === BaseType::FLOAT;
        $operandType = $lval->getType();

        return $this->builder->createInstruction($isFloat ? $floatOpcode : $intOpcode, [$lval, $rval], resultType: $operandType);
    }

    /**
     * Get a pointer (for load/store) from a variable expression.
     * Handles local variables and static properties.
     */
    protected function resolveVarPtr(\PhpParser\Node\Expr $var): ValueAbstract
    {
        if ($var instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            CompilerInvariant::check($var->class instanceof \PhpParser\Node\Name);
            CompilerInvariant::check($var->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $rawClass = $var->class->toString();
            if ($rawClass === 'self' || $rawClass === 'static') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($var->class, $this->currentNamespace());
            }
            $classMeta = $this->classRegistry[$className];
            $propType = $classMeta->staticProperties[$var->name->toString()];
            return new \App\PicoHP\LLVM\Value\Global_(ClassSymbol::mangle($className) . '_' . $var->name->toString(), $propType->toBase());
        }
        return PicoHPData::getPData($var)->getValue();
    }

    /**
     * Build argument values, filling in defaults for missing args.
     *
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     * @return array<ValueAbstract>
     */
    protected function buildArgsWithDefaults(array $args, \App\PicoHP\SymbolTable\Symbol $funcSymbol): array
    {
        $paramCount = count($funcSymbol->params);

        // If params aren't populated yet (pre-registered symbol), just build args as-is
        if ($paramCount === 0 && count($args) > 0) {
            $result = [];
            foreach ($args as $arg) {
                CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                $result[] = $this->buildExpr($arg->value);
            }
            return $result;
        }

        // Build a map of name => position for named arg resolution
        $nameToPos = array_flip($funcSymbol->paramNames);

        // Map args to positions (handle both positional and named)
        /** @var array<int, \PhpParser\Node\Expr> */
        $argsByPos = [];
        $positionalIndex = 0;
        foreach ($args as $arg) {
            CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
            if ($arg->name !== null) {
                // Named argument
                $name = $arg->name->toString();
                CompilerInvariant::check(isset($nameToPos[$name]), "unknown named argument: {$name}");
                $argsByPos[$nameToPos[$name]] = $arg->value;
            } else {
                // Positional argument
                $argsByPos[$positionalIndex] = $arg->value;
                $positionalIndex++;
            }
        }

        // Build values for each param position
        $result = [];
        for ($i = 0; $i < $paramCount; $i++) {
            if (isset($argsByPos[$i])) {
                $val = $this->buildExpr($argsByPos[$i]);
            } elseif (array_key_exists($i, $funcSymbol->defaults)) {
                $defaultExpr = $funcSymbol->defaults[$i];
                $val = $defaultExpr !== null ? $this->buildDefaultValue($defaultExpr) : new NullConstant();
            } else {
                throw new \RuntimeException("missing argument {$i} for function {$funcSymbol->name} (expects {$paramCount} params, got " . count($argsByPos) . ") with no default");
            }
            // Coerce int to float when param expects float (e.g. int|float union widened to float)
            if ($val->getType() === BaseType::INT && $funcSymbol->params[$i]->toBase() === BaseType::FLOAT) {
                $val = $this->builder->createSiToFp($val);
            }
            $result[] = $val;
        }
        return $result;
    }

    protected function buildDefaultValue(\PhpParser\Node\Expr $expr): ValueAbstract
    {
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, BaseType::INT);
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, BaseType::FLOAT);
        }
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $this->builder->createStringConstant($expr->value);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'null') {
                return new NullConstant();
            }
            return new Constant($name === 'true' ? 1 : 0, BaseType::BOOL);
        }
        if ($expr instanceof \PhpParser\Node\Expr\Array_) {
            // Empty array default: $param = []
            return $this->builder->createArrayNew();
        }
        if ($expr instanceof \PhpParser\Node\Expr\UnaryMinus && $expr->expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant(-$expr->expr->value, BaseType::INT);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            // Enum case as default value — resolve directly
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            $caseName = $expr->name->toString();
            if (isset($this->enumRegistry[$className])) {
                $tag = $this->enumRegistry[$className]->getCaseTag($caseName);
                return new Constant($tag, BaseType::INT);
            }
            throw new \RuntimeException("unsupported ClassConstFetch default: {$className}::{$caseName}");
        }
        if ($expr instanceof \PhpParser\Node\Expr\New_) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            $classMeta = $this->classRegistry[$className] ?? null;
            $typeId = $this->typeIdMap[$className] ?? 0;
            $llvmStruct = ClassSymbol::mangle($className);
            $objPtr = $this->builder->createObjectAlloc($llvmStruct, $typeId);
            $typeIdPtr = $this->builder->createStructGEP($llvmStruct, $objPtr, 0, BaseType::INT);
            $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
            if ($classMeta !== null) {
                $this->emitPropertyDefaults($className, $classMeta, $objPtr);
                if (isset($classMeta->methods['__construct']) && count($expr->args) > 0) {
                    $ctorSymbol = $classMeta->methods['__construct'];
                    $args = $this->buildArgsWithDefaults($expr->args, $ctorSymbol);
                    $allArgs = array_merge([$objPtr], $args);
                    $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                    $this->builder->createCall(ClassSymbol::llvmMethodSymbol($ctorOwner, '__construct'), $allArgs, BaseType::VOID);
                }
            }
            return $objPtr;
        }
        throw new \RuntimeException('unsupported default value type: ' . get_class($expr));
    }

    /**
     * PHP string concatenation (`a . b`) coerces each operand to string.
     */
    protected function coerceExprToStringForConcat(\PhpParser\Node\Expr $expr): ValueAbstract
    {
        $val = $this->buildExpr($expr);
        $picoType = $this->getExprResolvedType($expr);
        if ($picoType->isArray()) {
            return $this->builder->createStringConstant('Array');
        }

        return match ($picoType->toBase()) {
            BaseType::STRING => $val,
            BaseType::INT => $this->builder->createCall('pico_int_to_string', [$val], BaseType::STRING),
            BaseType::FLOAT => $this->builder->createCall('pico_float_to_string', [$val], BaseType::STRING),
            BaseType::BOOL => $this->builder->createSelect(
                $val,
                $this->builder->createStringConstant('1'),
                $this->builder->createStringConstant(''),
            ),
            BaseType::VOID => $this->builder->createStringConstant(''),
            BaseType::PTR => $val,
            BaseType::LABEL => throw new \RuntimeException('string concat on label value'),
        };
    }

    protected function getExprType(\PhpParser\Node\Expr $expr): PicoType
    {
        $pData = PicoHPData::getPData($expr);
        return $pData->getSymbol()->type;
    }

    /**
     * Built-in {@see \PhpParser\Node\Expr\FuncCall} nodes often have no {@see Symbol} on picoHP data;
     * mirror {@see SemanticAnalysisPass} return types so {@see coerceExprToStringForConcat} can classify values.
     */
    protected function inferBuiltinFuncCallReturnType(\PhpParser\Node\Expr\FuncCall $expr): PicoType
    {
        CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
        $fn = $expr->name->toLowerString();

        if ($this->builtinRegistry->has($fn)) {
            $def = $this->builtinRegistry->get($fn);
            if ($def->returnMatchesArg !== null) {
                $argIdx = $def->returnMatchesArg;
                if (count($expr->args) > $argIdx && $expr->args[$argIdx] instanceof \PhpParser\Node\Arg) {
                    return $this->getExprResolvedType($expr->args[$argIdx]->value);
                }
                return $def->returnType;
            }
            if ($def->returnElementType !== null) {
                $argIdx = $def->returnElementType;
                if (count($expr->args) > $argIdx && $expr->args[$argIdx] instanceof \PhpParser\Node\Arg) {
                    $arrType = $this->getExprResolvedType($expr->args[$argIdx]->value);
                    if ($arrType->isArray()) {
                        return $arrType->getElementType();
                    }
                }
                return PicoType::fromString('mixed');
            }
            return $def->returnType;
        }

        return PicoType::fromString('mixed');
    }

    protected function getExprResolvedType(\PhpParser\Node\Expr $expr): PicoType
    {
        if ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (is_string($expr->name) && $expr->name === 'this' && $this->ctx->className !== null) {
                return PicoType::object($this->ctx->className);
            }
            if (is_string($expr->name) && $expr->name === '_SERVER') {
                return PicoType::serverSuperglobalEmptyArray();
            }
            return PicoHPData::getPData($expr)->getSymbol()->type;
        }
        if ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objType = $this->getExprResolvedType($expr->var);
            if ($objType->isMixed()) {
                return PicoType::fromString('mixed');
            }
            // Enum ->value access (must match SemanticAnalysisPass; enum ClassMetadata has no "value" property)
            if ($objType->isEnum() && $expr->name->toString() === 'value') {
                $enumMeta = $this->enumRegistry[$objType->getClassName()];
                if ($enumMeta->backingType === 'string') {
                    return PicoType::fromString('string');
                }

                return PicoType::fromString('int');
            }
            $className = $objType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            // Resolve property type through descendants (interface/abstract)
            if (!isset($classMeta->properties[$propName])) {
                foreach ($this->findDescendants($className) as $descName) {
                    $descMeta = $this->classRegistry[$descName];
                    if (isset($descMeta->properties[$propName])) {
                        return $descMeta->getPropertyType($propName);
                    }
                }
            }
            return $classMeta->getPropertyType($propName);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            if ($expr->var instanceof \PhpParser\Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->var->name === '_SERVER'
            ) {
                return PicoType::fromString('mixed');
            }
            $arrType = $this->getExprResolvedType($expr->var);
            if ($arrType->isMixed()) {
                return PicoType::fromString('mixed');
            }
            CompilerInvariant::check($arrType->isArray());
            return $arrType->getElementType();
        }
        if ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objType = $this->getExprResolvedType($expr->var);
            if ($objType->isMixed()) {
                return PicoType::fromString('mixed');
            }
            $classMeta = $this->classRegistry[$objType->getClassName()];
            return $classMeta->methods[$expr->name->toString()]->type;
        }
        if ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $rawClass = $expr->class->toString();
            if ($rawClass === 'self') {
                CompilerInvariant::check($this->ctx->className !== null);
                $className = $this->ctx->className;
            } else {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
            }
            if (isset($this->enumRegistry[$className])) {
                return PicoType::enum($className);
            }
            // Class constants are scalar values, not object instances
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            if (isset($this->classRegistry[$className]) && isset($this->classRegistry[$className]->constants[$expr->name->toString()])) {
                return PicoType::fromString('int');
            }
            return PicoType::object($className);
        }
        if ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $pData = $expr->getAttribute('picoHP');
            if ($pData instanceof PicoHPData && $pData->symbol !== null) {
                return $pData->getSymbol()->type;
            }

            return $this->inferBuiltinFuncCallReturnType($expr);
        }
        if ($expr instanceof \PhpParser\Node\Expr\Exit_) {
            return PicoType::fromString('void');
        }
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return PicoType::fromString('string');
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return PicoType::fromString('int');
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return PicoType::fromString('float');
        }
        if ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $sigil = $expr->getOperatorSigil();
            if ($sigil === '.') {
                return PicoType::fromString('string');
            }
        }
        if ($expr instanceof \PhpParser\Node\Expr\Ternary) {
            if ($expr->if === null) {
                return $this->getExprResolvedType($expr->else);
            }
            $t = $this->getExprResolvedType($expr->if);
            $f = $this->getExprResolvedType($expr->else);
            if ($t->isEqualTo($f)) {
                return $t;
            }

            return PicoType::fromString('mixed');
        }
        if ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            return $this->getExprResolvedType($expr->right);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'null') {
                return PicoType::fromString('string');
            }
            if ($name === 'true' || $name === 'false') {
                return PicoType::fromString('bool');
            }
            return PicoType::fromString('int');
        }
        if ($expr instanceof \PhpParser\Node\Expr\New_) {
            if ($expr->class instanceof \PhpParser\Node\Name) {
                $className = ClassSymbol::fqcnFromResolvedName($expr->class, $this->currentNamespace());
                return PicoType::object($className);
            }
            return PicoType::fromString('mixed');
        }
        throw new \RuntimeException('getExprResolvedType: unsupported expr type ' . get_class($expr));
    }

    /**
     * Determine the BaseType of a match arm body expression from the semantic analysis data.
     */
    protected function resolveMatchArmType(\PhpParser\Node\Expr $expr): BaseType
    {
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return BaseType::STRING;
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return BaseType::INT;
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return BaseType::FLOAT;
        }
        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'null') {
                return BaseType::PTR;
            }
            return BaseType::BOOL;
        }
        // Fall back to resolved expression type (handles method/property calls too).
        $line = $expr->getStartLine();
        $exprType = get_debug_type($expr);
        try {
            return $this->getExprResolvedType($expr)->toBase();
        } catch (\Throwable $e) {
            throw new \RuntimeException("line {$line}, could not resolve match arm type for {$exprType}", 0, $e);
        }
    }

    /**
     * Coerce a value to i1 (bool) for use in branch conditions.
     */
    protected function coerceToBool(ValueAbstract $val): ValueAbstract
    {
        if ($val->getType() === BaseType::BOOL) {
            return $val;
        }
        if ($val->getType() === BaseType::PTR || $val->getType() === BaseType::STRING) {
            return $this->builder->createInstruction('icmp ne', [$val, new NullConstant()], resultType: BaseType::BOOL);
        }
        return $this->builder->createInstruction('icmp ne', [$val, new Constant(0, $val->getType())], resultType: BaseType::BOOL);
    }

    protected function buildShortCircuit(\PhpParser\Node\Expr\BinaryOp $expr, PicoHPData $pData): ValueAbstract
    {
        CompilerInvariant::check($this->ctx->function !== null);
        $isAnd = $expr->getOperatorSigil() === '&&';
        $count = $pData->mycount;

        $rhsBB = $this->ctx->function->addBasicBlock("sc_rhs{$count}");
        $endBB = $this->ctx->function->addBasicBlock("sc_end{$count}");
        $rhsLabel = new Label($rhsBB->getName());
        $endLabel = new Label($endBB->getName());

        $result = $this->builder->createEntryAlloca($this->ctx->function, "sc_result{$count}", BaseType::BOOL);
        $lval = $this->coerceToBool($this->buildExpr($expr->left));
        $this->builder->createStore($lval, $result);

        if ($isAnd) {
            $this->builder->createBranch([$lval, $rhsLabel, $endLabel]);
        } else {
            $this->builder->createBranch([$lval, $endLabel, $rhsLabel]);
        }

        $this->builder->setInsertPoint($rhsBB);
        $rval = $this->buildExpr($expr->right);
        $this->builder->createStore($rval, $result);
        $this->builder->createBranch([$endLabel]);

        $this->builder->setInsertPoint($endBB);
        return $this->builder->createLoad($result);
    }

    protected function buildArrayInit(\PhpParser\Node\Expr\Array_ $arrayExpr, PicoType $arrayType): ValueAbstract
    {
        if ($arrayType->isArray() && $arrayType->hasStringKeys()) {
            return $this->buildMapInit($arrayExpr, $arrayType);
        }
        $arrPtr = $this->builder->createArrayNew();
        $elementType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
        foreach ($arrayExpr->items as $item) {
            $elemVal = $this->buildExpr($item->value);
            $this->builder->createArrayPush($arrPtr, $elemVal, $elementType);
        }
        return $arrPtr;
    }

    protected function buildMapInit(\PhpParser\Node\Expr\Array_ $arrayExpr, PicoType $arrayType): ValueAbstract
    {
        $mapPtr = $this->builder->createCall('pico_map_new', [], BaseType::PTR);
        $elementType = $arrayType->getElementBaseType();
        foreach ($arrayExpr->items as $item) {
            CompilerInvariant::check($item->key !== null);
            $keyVal = $this->buildExpr($item->key);
            $elemVal = $this->buildExpr($item->value);
            $setFunc = 'pico_map_set_' . match ($elementType) {
                BaseType::INT => 'int',
                BaseType::FLOAT => 'float',
                BaseType::BOOL => 'bool',
                BaseType::STRING => 'str',
                BaseType::PTR => 'ptr',
                default => throw new \RuntimeException("unsupported map value type: {$elementType->value}"),
            };
            $this->builder->createCall($setFunc, [$mapPtr, $keyVal, $elemVal], BaseType::VOID);
        }
        return $mapPtr;
    }
}

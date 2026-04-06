<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\LLVM\Value\Instruction;
use App\PicoHP\Tree\{NodeInterface, NodeTrait};
use App\PicoHP\{PicoType};

// information about our module
class Module implements NodeInterface
{
    use NodeTrait;

    protected string $name;
    protected Builder $builder;
    protected DebugInfo $debugInfo;

    /**
     * @var array<IRLine>
     */
    protected array $globalLines = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->builder = new Builder($this, "arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");
        $this->debugInfo = new DebugInfo();
        Instruction::resetCounter();
    }

    /**
     * @param array<PicoType> $params
     */
    public function addFunction(string $name, PicoType $returnType, array $params = [], bool $canThrow = false): Function_
    {
        $f = new Function_($name, $returnType, $params, $canThrow);
        $this->addChild($f);
        return $f;
    }

    public function hasFunction(string $name): bool
    {
        foreach ($this->getChildren() as $child) {
            if ($child instanceof Function_ && $child->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    public function getDebugInfo(): DebugInfo
    {
        return $this->debugInfo;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->globalLines[] = $line;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $code = $this->globalLines;

        // render out functions and blocks within
        foreach ($this->getChildren() as $function) {
            \App\PicoHP\CompilerInvariant::check($function instanceof Function_);
            $code = array_merge($code, $function->getLines());
        }

        return $code;
    }

    /**
     * @param resource $file
     */
    public function print($file = STDOUT): void
    {
        $lines = $this->getLines();

        // Collect defined/declared symbols, then emit stubs for missing ones
        $defined = [];
        $called = [];
        foreach ($lines as $line) {
            $text = $line->toString();
            if (preg_match('/^define\s.*@(\S+)\s*\(/', $text, $m) === 1) {
                $defined[$m[1]] = true;
            }
            if (preg_match('/^declare\s.*@(\S+)\s*\(/', $text, $m) === 1) {
                $defined[$m[1]] = true;
            }
            if (preg_match_all('/call\s+[^@]*@(\S+)\s*\(/', $text, $matches) >= 1) {
                foreach ($matches[1] as $fn) {
                    $called[$fn] = true;
                }
            }
        }

        foreach ($lines as $line) {
            fwrite($file, $line->toString() . PHP_EOL);
        }
        // Emit abort-stub defines for any called-but-not-defined functions
        foreach ($called as $fn => $_) {
            if (!isset($defined[$fn])) {
                $nameLen = strlen($fn) + 1;
                fwrite($file, "@.stub.name.{$fn} = private constant [{$nameLen} x i8] c\"{$fn}\\00\"\n");
                fwrite($file, "define void @{$fn}(...) {\nentry:\n");
                fwrite($file, "    call void @picohp_unimplemented(ptr @.stub.name.{$fn})\n");
                fwrite($file, "    unreachable\n}\n\n");
            }
        }
        foreach ($this->debugInfo->getMetadataLines() as $metaLine) {
            fwrite($file, $metaLine . PHP_EOL);
        }
    }
}

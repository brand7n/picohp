<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class Function_ implements NodeInterface
{
    use NodeTrait;

    protected string $name;
    protected ?string $returnType;

    /**
     * @var array<string>
     */
    protected array $params;

    /**
     * @param array<string> $params
     */
    public function __construct(string $name, array $params = [], ?string $returnType = null)
    {
        $this->name = $name;
        $this->params = $params;
        $this->returnType = $returnType;
    }

    public function addBasicBlock(string $name): BasicBlock
    {
        $bb = new BasicBlock($name);
        $this->addChild($bb);
        return $bb;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public static function getType(string $strType): string
    {
        switch ($strType) {
            case 'int':
                $type = "i32";
                break;
            case 'float':
                $type = "float";
                break;
            case 'bool':
                $type = "i1";
                break;
            default:
                throw new \RuntimeException("Unknown type: {$strType}");
        }
        return $type;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $code = [];
        $params = [];
        $count = 0;
        foreach ($this->params as $param) {
            $type = self::getType($param);
            $params[] = "{$type} %{$count}";
            $count++;
        }
        $paramString = implode(', ', $params);
        $returnType = self::getType($this->returnType ?? 'void');
        $code[] = new IRLine("define dso_local {$returnType} @{$this->name}({$paramString}) {");
        foreach ($this->getChildren() as $bb) {
            assert($bb instanceof BasicBlock);
            $code = array_merge($code, $bb->getLines());
        }
        $code[] = new IRLine("}");
        $code[] = new IRLine();
        return $code;
    }
}

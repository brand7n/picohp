<?php

declare(strict_types=1);

namespace App\Parser;

class Function_
{
    protected \PhpParser\Node $node;

    public function __construct(\PhpParser\Node $node)
    {
        $this->node = $node;
    }

    public function getName(): string
    {
        if (! $this->node instanceof \PhpParser\Node\Stmt\Function_) {
            throw new \Exception('wrong node type');
        }

        return $this->node->name->toString();
    }

    /**
     * @return array<string>
     */
    public function getParams(): array
    {
        if (! $this->node instanceof \PhpParser\Node\Stmt\Function_) {
            throw new \Exception('wrong node type');
        }

        return [];
    }
}

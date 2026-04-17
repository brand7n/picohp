<?php

declare(strict_types=1);

namespace PicoIR;

class Constant extends ValueAbstract
{
    public function __construct(
        public readonly int|float $value,
        BaseType $type,
    ) {
        parent::__construct($type);
    }

    public function renderRef(): string
    {
        if ($this->type === BaseType::FLOAT) {
            // LLVM requires hex for non-trivial floats; 0.0 is fine as literal
            return sprintf('%e', $this->value);
        }
        return (string) $this->value;
    }
}

class NullConstant extends ValueAbstract
{
    public function __construct(BaseType $type = BaseType::PTR)
    {
        parent::__construct($type);
    }

    public function renderRef(): string
    {
        return 'null';
    }
}

class Param extends ValueAbstract
{
    public function __construct(
        public readonly int $index,
        BaseType $type,
    ) {
        parent::__construct($type);
    }

    public function renderRef(): string
    {
        return "%{$this->index}";
    }
}

class Global_ extends ValueAbstract
{
    public function __construct(string $name, BaseType $type)
    {
        parent::__construct($type);
        $this->setName($name);
    }

    public function renderRef(): string
    {
        return "@{$this->getName()}";
    }
}

class Label extends ValueAbstract
{
    public function __construct(string $name)
    {
        parent::__construct(BaseType::LABEL);
        $this->setName($name);
    }

    public function renderRef(): string
    {
        return "%{$this->getName()}";
    }
}

class Void_ extends ValueAbstract
{
    public function __construct()
    {
        parent::__construct(BaseType::VOID);
    }

    public function renderRef(): string
    {
        return '';
    }
}

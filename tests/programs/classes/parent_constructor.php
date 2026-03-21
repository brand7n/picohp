<?php

declare(strict_types=1);

class Base
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

class Child extends Base
{
    public string $label;

    public function __construct(int $id, string $label)
    {
        parent::__construct($id);
        $this->label = $label;
    }
}

$c = new Child(42, 'test');
echo $c->id;
echo "\n";
echo $c->label;
echo "\n";

<?php

declare(strict_types=1);

interface HasNoReturnType
{
    public function setValue(int $x);
}

class ImplNoReturnType implements HasNoReturnType
{
    public int $value = 0;

    public function setValue(int $x)
    {
        $this->value = $x;
        // No explicit return type and no return statement (implicit null).
    }
}

function run(HasNoReturnType $x): void
{
    $x->setValue(123);
    echo "ok\n";
}

run(new ImplNoReturnType());

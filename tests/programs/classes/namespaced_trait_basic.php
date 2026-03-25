<?php

declare(strict_types=1);

namespace N1;

trait T
{
    public function f(): int
    {
        return 7;
    }
}

class C
{
    use T;

    public function run(): void
    {
        echo $this->f();
        echo "\n";
    }
}

$c = new C();
$c->run();

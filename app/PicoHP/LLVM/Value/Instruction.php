<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

// A class representing an instruction (e.g., an arithmetic operation like addition)
class Instruction extends ValueAbstract
{
    protected static int $counter = 1;
    protected int $count;

    public function __construct(string $name, string $type)
    {
        parent::__construct($type);
        $this->count = self::$counter++;
        $this->setName(str_replace(' ', '', $name));
    }

    public function render(): string
    {
        return "%{$this->getName()}_result{$this->count}";
    }

    public static function resetCounter(): void
    {
        static::$counter = 1;
    }
}

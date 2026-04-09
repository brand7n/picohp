<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\BaseType;
use App\PicoHP\LLVM\ValueAbstract;

// A class representing a constant value (e.g., integer, float)
class Constant extends ValueAbstract
{
    private int|float $value;

    public function __construct(float|int $value, BaseType $type)
    {
        parent::__construct($type);
        $this->value = $value;
    }

    // Get the constant value
    public function getValue(): int|float
    {
        return $this->value;
    }

    // Represent the constant as a string in LLVM IR format
    public function render(): string
    {
        if ($this->getType() === BaseType::FLOAT) {
            return $this->floatToHex($this->value);
        }
        // Pointer constants must use 'null' not '0' in LLVM IR.
        if (($this->getType() === BaseType::PTR || $this->getType() === BaseType::STRING) && $this->value === 0) {
            return 'null';
        }
        return (string)$this->value;
    }

    protected function floatToHex(float $float): string
    {
        // Pack as IEEE 754 double (64-bit)
        $packed = pack('d', $float);

        // Unpack to 64-bit unsigned integer
        $unpacked = unpack('Q', $packed);
        \App\PicoHP\CompilerInvariant::check(isset($unpacked[1]) && is_int($unpacked[1]));

        // Return full 64-bit hexadecimal representation
        return '0x' . strtoupper(str_pad(dechex($unpacked[1]), 16, '0', STR_PAD_LEFT));
    }
}

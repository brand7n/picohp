<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\BaseType;
use App\PicoHP\LLVM\ValueAbstract;
use Illuminate\Support\Str;

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
        return (string)$this->value;
    }

    // TODO: for now don't worry why LLVM wants 32 bits of zeros, but it may be a layout conf issue?
    protected function floatToHex(float $float): string
    {
        // Pack the float into binary string (IEEE 754 format)
        $packed = pack('d', $float);

        // Unpack the binary string to a 32-bit unsigned integer
        $unpacked = unpack('Q', $packed);
        assert(isset($unpacked[1]) && is_int($unpacked[1]));

        // Return the hexadecimal representation
        $trunc = Str::substr(strtoupper(dechex($unpacked[1])), 0, 8);
        return "0x{$trunc}00000000";
    }
}

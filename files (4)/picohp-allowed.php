<?php

declare(strict_types=1);

// Scalars and arithmetic
$a = 42;
$b = 3.14;
$c = true;
$d = 'hello';
$e = $a + 10;
$f = $a * $b;

// String concatenation
$g = $d . ' world';

// Typed function
function add(int $x, int $y): int
{
    return $x + $y;
}

// Control flow
if ($a > 10) {
    $h = 1;
} elseif ($a > 5) {
    $h = 2;
} else {
    $h = 3;
}

// Loops
for ($i = 0; $i < 10; $i++) {
    $a = $a + 1;
}

while ($a > 0) {
    $a = $a - 1;
}

// Match expression
$result = match ($h) {
    1 => 'one',
    2 => 'two',
    default => 'other',
};

// Class with typed properties and methods
final class Point
{
    public function __construct(
        private readonly float $x,
        private readonly float $y,
    ) {
    }

    public function distanceTo(self $other): float
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        return sqrt($dx * $dx + $dy * $dy);
    }
}

// Interface
interface Shape
{
    public function area(): float;
}

// Implementing an interface
final class Circle implements Shape
{
    public function __construct(
        private readonly float $radius,
    ) {
    }

    public function area(): float
    {
        return M_PI * $this->radius * $this->radius;
    }
}

// Enum
enum Color: string
{
    case Red = 'red';
    case Blue = 'blue';
}

// Array (typed)
/** @var array<int, string> */
$names = ['alice', 'bob'];

// Closure (non-dynamic)
$double = function (int $x): int {
    return $x * 2;
};

// Arrow function
$triple = fn (int $x): int => $x * 3;

// Static include (constant path)
// require __DIR__ . '/some-file.php';

// Static method call
// Point::someStaticMethod();

// Named arguments
$p = new Point(x: 1.0, y: 2.0);

echo add(1, 2);
echo "\n";

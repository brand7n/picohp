# picoHP Agent Task Issues

Copy each section below into a separate GitHub issue using:
```bash
gh issue create --label agent-task --title "<title>" --body "<body>"
```

---

## Issue 1: Support __toString magic method

**Title:** `feat: support __toString magic method`

**Body:**

## Feature

Support the `__toString()` magic method on classes. When an object is used in a string context (echo, concatenation, string cast), the compiler should emit a call to the object's `__toString()` method.

## Why

php-parser's `Name` and `Identifier` classes implement `__toString()`. This is a prerequisite for self-hosting.

## PHP Behavior

```php
<?php
declare(strict_types=1);

class Greeting {
    public function __construct(private readonly string $value) {}
    
    public function __toString(): string {
        return $this->value;
    }
}

$g = new Greeting("hello");
echo $g;           // prints: hello
echo $g . " world"; // prints: hello world
$s = (string) $g;
echo $s;           // prints: hello
```

## Test Cases

- `tests/programs/tostring/basic_tostring.php` — echo an object with __toString
- `tests/programs/tostring/concat_tostring.php` — concatenate object with string
- `tests/programs/tostring/cast_tostring.php` — explicit (string) cast on object

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests
- Only `__toString` needs to work — other magic methods are out of scope

## Estimated Complexity

Low — the method already exists on the class, just need to emit a call to it in string contexts.

---

## Issue 2: Support typed closures and arrow functions as values

**Title:** `feat: support closures and arrow functions as values`

**Body:**

## Feature

Support creating closures and arrow functions and storing them in variables. The closure should be callable via the variable.

## Why

php-parser uses closures extensively in its callback table and in array operations like `array_filter` and `usort`. This is a prerequisite for self-hosting.

## PHP Behavior

```php
<?php
declare(strict_types=1);

$double = function (int $x): int {
    return $x * 2;
};

echo $double(21);  // prints: 42

$triple = fn (int $x): int => $x * 3;

echo $triple(14);  // prints: 42

function apply(int $val, callable $fn): int {
    return $fn($val);
}

echo apply(10, $double); // prints: 20
echo apply(10, $triple); // prints: 30
```

## Test Cases

- `tests/programs/closures/basic_closure.php` — assign closure to variable, invoke it
- `tests/programs/closures/arrow_function.php` — arrow function assigned to variable
- `tests/programs/closures/closure_as_param.php` — pass closure as function parameter
- `tests/programs/closures/closure_capture.php` — closure capturing outer variable with `use`

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests
- Closures with explicit type signatures only — no untyped closures

## Estimated Complexity

Medium — closures need to be lowered to function pointers or structs containing a function pointer + captured environment.

---

## Issue 3: Support static closures in arrays

**Title:** `feat: support storing and invoking closures from arrays`

**Body:**

## Feature

Support storing closures in arrays and invoking them by index. This includes arrays of static closures.

## Why

php-parser's generated `Php8.php` stores ~600 static closures in a `reduceCallbacks` array and invokes them via `$this->reduceCallbacks[$rule]($this, $stackPos)`. This is the core dispatch mechanism of the LALR parser.

## PHP Behavior

```php
<?php
declare(strict_types=1);

/** @var array<int, callable(int): int> */
$ops = [
    0 => static function (int $x): int { return $x + 1; },
    1 => static function (int $x): int { return $x * 2; },
    2 => static function (int $x): int { return $x - 3; },
];

$val = 10;
$val = $ops[0]($val);
echo $val; // 11
$val = $ops[1]($val);
echo $val; // 22
$val = $ops[2]($val);
echo $val; // 19

echo "\n";
```

## Test Cases

- `tests/programs/closures/array_of_closures.php` — store closures in array, invoke by index
- `tests/programs/closures/nullable_closure_array.php` — array with null and closure entries (matching parser's pattern where some reduce rules are null)
- `tests/programs/closures/static_closure_array.php` — static closures specifically

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests
- Depends on Issue 2

## Estimated Complexity

Medium — builds on closure support from Issue 2, adds array storage and indirect invocation.

---

## Issue 4: Support foreach loops

**Title:** `feat: support foreach loops`

**Body:**

## Feature

Support `foreach` loops over arrays, both indexed and associative.

## Why

php-parser's NodeTraverser iterates over AST nodes with foreach. The compiler's own code uses foreach extensively. This is a prerequisite for self-hosting.

## PHP Behavior

```php
<?php
declare(strict_types=1);

/** @var array<int, string> */
$names = ['alice', 'bob', 'charlie'];

foreach ($names as $name) {
    echo $name . "\n";
}

/** @var array<string, int> */
$ages = ['alice' => 30, 'bob' => 25];

foreach ($ages as $key => $value) {
    echo $key . ': ' . $value . "\n";
}
```

## Test Cases

- `tests/programs/foreach/indexed_array.php` — foreach over indexed array
- `tests/programs/foreach/associative_array.php` — foreach with key => value
- `tests/programs/foreach/nested_foreach.php` — nested foreach loops
- `tests/programs/foreach/foreach_break_continue.php` — break and continue inside foreach

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests
- Requires array support to be working

## Estimated Complexity

Medium — requires array iteration protocol in the runtime.

---

## Issue 5: Support try/catch/finally

**Title:** `feat: support try/catch/finally exception handling`

**Body:**

## Feature

Support try/catch/finally blocks with typed catch clauses.

## Why

php-parser and the compiler's own code use exceptions for error handling. This is a prerequisite for self-hosting.

## PHP Behavior

```php
<?php
declare(strict_types=1);

class ParseError extends \RuntimeException {}

function riskyOperation(int $x): int {
    if ($x === 0) {
        throw new \RuntimeException('zero');
    }
    if ($x < 0) {
        throw new ParseError('negative');
    }
    return $x * 2;
}

try {
    echo riskyOperation(5) . "\n";  // prints: 10
} catch (ParseError $e) {
    echo "parse error: " . $e->getMessage() . "\n";
} catch (\RuntimeException $e) {
    echo "runtime error: " . $e->getMessage() . "\n";
} finally {
    echo "done\n";
}

try {
    echo riskyOperation(0) . "\n";
} catch (\RuntimeException $e) {
    echo "caught: " . $e->getMessage() . "\n";
} finally {
    echo "cleanup\n";
}
```

## Test Cases

- `tests/programs/exceptions/basic_try_catch.php` — simple try/catch
- `tests/programs/exceptions/multiple_catch.php` — multiple catch clauses
- `tests/programs/exceptions/finally.php` — try/catch/finally
- `tests/programs/exceptions/throw_in_function.php` — throw from a called function
- `tests/programs/exceptions/custom_exception.php` — user-defined exception class

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests
- Requires class inheritance to be working (exceptions extend RuntimeException)

## Estimated Complexity

High — exceptions require LLVM's landingpad/invoke mechanism or a setjmp/longjmp approach in the runtime. This is one of the harder features to implement correctly.

---

## Issue 6: Support nullable types and null coalescing

**Title:** `feat: support nullable types and null coalescing operator`

**Body:**

## Feature

Support `?Type` nullable type declarations, null checks, and the `??` null coalescing operator.

## Why

php-parser uses nullable types pervasively (`?Node`, `?string`, etc.). The compiler's own code does too. This is a prerequisite for self-hosting.

## PHP Behavior

```php
<?php
declare(strict_types=1);

function greet(?string $name): string {
    return 'hello ' . ($name ?? 'world');
}

echo greet('alice') . "\n";  // prints: hello alice
echo greet(null) . "\n";     // prints: hello world

function findValue(?int $val): int {
    if ($val === null) {
        return -1;
    }
    return $val * 2;
}

echo findValue(21) . "\n";   // prints: 42
echo findValue(null) . "\n"; // prints: -1
```

## Test Cases

- `tests/programs/nullable/nullable_param.php` — function with nullable parameter
- `tests/programs/nullable/null_coalesce.php` — ?? operator
- `tests/programs/nullable/null_check.php` — === null / !== null checks
- `tests/programs/nullable/nullable_return.php` — function returning nullable type

## Acceptance Criteria

- All new test files pass (compiled binary output matches `php` interpreter output)
- No regressions in existing tests

## Estimated Complexity

Medium — nullable types need a tagged representation (value or null). For scalars, could use a flag bit or a wrapper. For objects, null is just a null pointer.

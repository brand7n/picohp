<?php

declare(strict_types=1);

eval('echo "bad";');

// goto
goto myLabel;
myLabel:

// generator
function gen(): \Generator { yield 1; }

// global
function usesGlobal(): void { global $x; }

// error suppression
$val = @file_get_contents('nope');

extract(['a' => 1]);
compact('a');

// dynamic function call
$func = 'strlen';
$func('hello');

// dynamic method call
$method = 'toString';
$obj = new \stdClass();
$obj->$method();

// dynamic instantiation
$class = 'stdClass';
new $class();

// dynamic property access
$prop = 'name';
$obj->$prop;

// magic method
class HasMagic {
    public function __get(string $name): mixed { return null; }
}

// reflection
new \ReflectionClass('stdClass');

\Closure::bind(function (): void {}, null);

require $dynamicPath;

call_user_func('strlen', 'hello');

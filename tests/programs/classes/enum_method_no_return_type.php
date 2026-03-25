<?php

declare(strict_types=1);

enum MyEnum
{
    case A;

    public function doIt(int $x)
    {
        // No explicit return type and no return statement (implicit null).
        // $x is unused intentionally.
        $unused = $x;
    }
}

// We intentionally do not call `doIt()` here:
// picohp currently doesn't emit IR bodies for enum methods, so calling the
// method would fail at link-time. This program exists to exercise semantic
// registration of the enum method signature (return type defaulting).
echo "enum-ok\n";

<?php

function print_value(int $x): void
{
    echo $x;
}

function print_twice(int $x): void
{
    print_value($x);
    print_value($x);
}

print_twice(42);

<?php

function print_msg(string $msg): int
{
    echo $msg;
    return 0;
}

function get_greeting(): string
{
    return "hi";
}

print_msg("hello");
print_msg(get_greeting());

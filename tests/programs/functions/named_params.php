<?php

declare(strict_types=1);

function createUser(string $name, int $age = 0, string $role = 'user'): string
{
    return $name . ' ' . $role;
}

echo createUser('alice', 30, 'admin');
echo "\n";
echo createUser(name: 'bob', role: 'editor');
echo "\n";
echo createUser('carol');
echo "\n";
echo createUser(role: 'mod', name: 'dave', age: 25);
echo "\n";

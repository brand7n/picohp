<?php

declare(strict_types=1);

/** @var array<string, string> $person */
$person = ['name' => 'Alice', 'city' => 'NYC'];

echo $person['name'];
echo "\n";
echo $person['city'];
echo "\n";

$person['city'] = 'LA';
echo $person['city'];
echo "\n";

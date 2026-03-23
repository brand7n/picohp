<?php

declare(strict_types=1);

/** @var array<string, int> $scores */
$scores = ['math' => 95, 'english' => 87, 'science' => 92];

echo $scores['math'];
echo "\n";
echo $scores['science'];
echo "\n";

$scores['english'] = 90;
echo $scores['english'];
echo "\n";

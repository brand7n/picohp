<?php

declare(strict_types=1);

$result = preg_match('/^hello/', 'hello world');
echo $result;
echo "\n";

$result2 = preg_match('/^foo/', 'bar');
echo $result2;
echo "\n";

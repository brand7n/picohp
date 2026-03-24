<?php

declare(strict_types=1);

/** @var string $s */
$s = "hello world";

/** @var string $sub1 */
$sub1 = substr($s, 6, 5);
echo $sub1;
echo "\n";

/** @var string $sub2 */
$sub2 = substr($s, 0, 5);
echo $sub2;
echo "\n";

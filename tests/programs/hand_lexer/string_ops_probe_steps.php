<?php

declare(strict_types=1);

echo "s0\n";
$s = '<?php echo "hello world";';
echo "s1\n";

$rest = substr($s, 0);
echo "s2\n";

$prefix5 = substr($rest, 0, 5);
echo "s3\n";

$next = substr($rest, 5, 1);
echo "s4\n";

$lower = strtolower($prefix5);
echo "s5\n";

$chars = ['<', '?', 'p', 'h', 'p'];
$target = '';
$i = 0;
while ($i < count($chars)) {
    $target = $target . $chars[$i];
    $i = $i + 1;
}

echo $prefix5 . "\n";
echo $lower . "\n";
echo $next . "\n";
echo ($lower === $target ? '1' : '0') . "\n";

<?php

declare(strict_types=1);

/** @var array<int, string> $m */
$m = [];
$ok = preg_match('/^<\?php(?=[ \t\r\n])/i', '<?php echo "hello world";', $m);
if ($ok === 1) {
    echo "open:1:" . $m[0] . "\n";
} else {
    echo "open:0:\n";
}

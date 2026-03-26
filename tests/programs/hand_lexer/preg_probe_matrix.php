<?php

declare(strict_types=1);

/**
 * Minimal regex compatibility matrix for PicoHP preg_match.
 */
function runProbe(string $label, string $pattern, string $subject): void
{
    /** @var array<int, string> $m */
    $m = [];
    $ok = preg_match($pattern, $subject, $m);
    if ($ok === 1) {
        echo $label . ":1:" . $m[0] . "\n";
    } else {
        echo $label . ":0:\n";
    }
}

runProbe('lookahead_open', '/^<\?php(?=[ \t\r\n])/i', '<?php echo "x";');
runProbe('open_nolookahead', '/^<\?php/i', '<?php echo "x";');
runProbe('ident_class', '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', 'hello123');
runProbe('var_escape', '/^\$[a-zA-Z_][a-zA-Z0-9_]*/', '$abc123');
runProbe('alt_inline', '/^[^<]+|^<(?!\?)/', 'hello<');
runProbe('lazy_block', '/^\/\*.*?\*\//s', '/*abc*/');

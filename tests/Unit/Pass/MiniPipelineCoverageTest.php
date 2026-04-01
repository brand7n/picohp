<?php

declare(strict_types=1);

/** @return list<string> */
function picohpMiniPipelineSources(): array
{
    return [
        '<?php declare(strict_types=1); echo strval(1);',
        '<?php declare(strict_types=1); echo strval(1 + 2);',
        '<?php declare(strict_types=1); echo strval(1 - 2);',
        '<?php declare(strict_types=1); echo strval(2 * 3);',
        '<?php declare(strict_types=1); echo strval(7 / 2);',
        '<?php declare(strict_types=1); echo strval(7 % 3);',
        '<?php declare(strict_types=1); $a = 1; $b = 2; echo strval($a + $b);',
        '<?php declare(strict_types=1); $x = true; echo $x ? "1" : "0";',
        '<?php declare(strict_types=1); $x = false; echo $x ? "1" : "0";',
        '<?php declare(strict_types=1); $a = 1; $a += 2; echo strval($a);',
        '<?php declare(strict_types=1); $a = 3; $a -= 1; echo strval($a);',
        '<?php declare(strict_types=1); $a = 2; $a = $a * 3; echo strval($a);',
        '<?php declare(strict_types=1); $a = 10; $a = $a / 2; echo strval($a);',
        '<?php declare(strict_types=1); $a = 10; $a = $a % 3; echo strval($a);',
        '<?php declare(strict_types=1); echo strval(1 << 2);',
        '<?php declare(strict_types=1); echo strval(16 >> 2);',
        '<?php declare(strict_types=1); echo strval(3 & 1);',
        '<?php declare(strict_types=1); echo strval(1 | 2);',
        '<?php declare(strict_types=1); echo strval(1 + 2 * 3);',
        '<?php declare(strict_types=1); echo strval(0);',
        '<?php declare(strict_types=1); $a = 1; echo strval($a + 0);',
        '<?php declare(strict_types=1); $a = 5; echo strval(-$a);',
        '<?php declare(strict_types=1); echo strval((int) 3.7);',
        '<?php declare(strict_types=1); echo strval((float) 2);',
        '<?php declare(strict_types=1); echo (true && true) ? "1" : "0";',
        '<?php declare(strict_types=1); echo (true || false) ? "1" : "0";',
        '<?php declare(strict_types=1); echo (!false) ? "1" : "0";',
        '<?php declare(strict_types=1); echo strval(1 === 1 ? 1 : 0);',
        '<?php declare(strict_types=1); echo strval(1 !== 2 ? 1 : 0);',
        '<?php declare(strict_types=1); echo strval(1 < 2 ? 1 : 0);',
        '<?php declare(strict_types=1); echo strval(2 > 1 ? 1 : 0);',
        '<?php declare(strict_types=1); echo strval(1 <= 1 ? 1 : 0);',
        '<?php declare(strict_types=1); echo strval(2 >= 2 ? 1 : 0);',
        '<?php declare(strict_types=1); echo "a" . "b";',
        '<?php declare(strict_types=1); $s = "x"; echo $s . "y";',
        '<?php declare(strict_types=1); $a = [1, 2, 3]; echo strval($a[0]);',
        '<?php declare(strict_types=1); $a = [1, 2]; $a[] = 3; echo strval(count($a));',
        '<?php declare(strict_types=1); if (true) { echo "1"; } else { echo "0"; }',
        '<?php declare(strict_types=1); $i = 0; while ($i < 2) { $i = $i + 1; } echo strval($i);',
        '<?php declare(strict_types=1); for ($i = 0; $i < 2; $i = $i + 1) { } echo strval($i);',
        '<?php declare(strict_types=1); $a = [1, 2]; foreach ($a as $v) { echo strval($v); }',
        '<?php declare(strict_types=1); $a = [1, 2]; foreach ($a as $k => $v) { echo strval($k); echo strval($v); }',
        '<?php declare(strict_types=1); do { $x = 1; } while (false); echo strval($x);',
        '<?php declare(strict_types=1); $x = 1; switch ($x) { case 1: echo "1"; break; default: echo "0"; }',
        '<?php declare(strict_types=1); function f(int $a): int { return $a + 1; } echo strval(f(1));',
        '<?php declare(strict_types=1); final class C { public int $x = 1; } $o = new C(); echo strval($o->x);',
        '<?php declare(strict_types=1); final class C { public static function s(): int { return 2; } } echo strval(C::s());',
        '<?php declare(strict_types=1); interface I { public function m(): int; } final class C implements I { public function m(): int { return 3; } } $o = new C(); echo strval($o->m());',
        '<?php declare(strict_types=1); enum E: int { case A = 1; } echo strval(E::A->value);',
        '<?php declare(strict_types=1); try { throw new \Exception("x"); } catch (\Exception $e) { echo "caught"; }',
        '<?php declare(strict_types=1); $a = null; echo ($a ?? 2) === 2 ? "1" : "0";',
        '<?php declare(strict_types=1); echo strval(match (2) { 1 => 10, 2 => 20, default => 0 });',
    ];
}

foreach (picohpMiniPipelineSources() as $i => $src) {
    it('mini pipeline case '.$i, function () use ($src): void {
        expect($src)->not->toBe('');
        picohpRunMiniPipeline($src);
    });
}

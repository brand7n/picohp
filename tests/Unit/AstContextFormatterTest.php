<?php

declare(strict_types=1);

use App\PicoHP\AstContextFormatter;
use PhpParser\Node\Scalar\String_;

it('formats path:line when file and line are set', function () {
    $n = new String_('a');
    $n->setAttribute('pico_source_file', '/proj/a.php');
    $n->setAttribute('startLine', 12);
    expect(AstContextFormatter::location($n))->toBe('/proj/a.php:12');
});

it('formats file only when line is not positive', function () {
    $n = new String_('a');
    $n->setAttribute('pico_source_file', '/proj/a.php');
    $n->setAttribute('startLine', 0);
    expect(AstContextFormatter::location($n))->toBe('/proj/a.php');
});

it('formats line N when only line is available', function () {
    $n = new String_('a');
    $n->setAttribute('startLine', 7);
    expect(AstContextFormatter::location($n))->toBe('line 7');
});

it('uses unknown location when nothing is set', function () {
    $n = new String_('a');
    $n->setAttribute('startLine', 0);
    expect(AstContextFormatter::location($n))->toBe('unknown location');
});

it('formats full context including code', function () {
    $n = new String_('hi');
    $n->setAttribute('pico_source_file', 'f.php');
    $n->setAttribute('startLine', 1);
    $out = AstContextFormatter::format($n);
    expect($out)->toContain('file:');
    expect($out)->toContain('code:');
});

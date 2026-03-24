<?php

declare(strict_types=1);

it('fails gracefully when input file does not exist', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build nonexistent_file.php')->assertExitCode(1);
});

it('fails gracefully on unparseable PHP', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pico_');
    assert(is_string($tmp));
    file_put_contents($tmp, '<?php (');

    $threw = false;
    try {
        /** @phpstan-ignore-next-line */
        $this->artisan("build {$tmp}");
    } catch (\PhpParser\Error) {
        $threw = true;
    } finally {
        unlink($tmp);
    }
    expect($threw)->toBeTrue();
});

it('fails gracefully when directory has no entry point', function () {
    $tmp = sys_get_temp_dir() . '/pico_test_' . getmypid();
    @mkdir($tmp);
    @mkdir($tmp . '/vendor/composer', 0755, true);
    file_put_contents($tmp . '/vendor/composer/autoload_classmap.php', '<?php return [];');

    try {
        /** @phpstan-ignore-next-line */
        $this->artisan("build {$tmp}");
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('Entry point not found');
    } finally {
        unlink($tmp . '/vendor/composer/autoload_classmap.php');
        rmdir($tmp . '/vendor/composer');
        rmdir($tmp . '/vendor');
        rmdir($tmp);
    }
});

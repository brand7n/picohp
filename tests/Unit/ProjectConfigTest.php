<?php

declare(strict_types=1);

use App\Support\ProjectConfig;

it('returns the full app config when the key is null', function () {
    $all = ProjectConfig::get(null);
    expect($all)->toBeArray();
    expect($all)->toHaveKey('build_path');
});

it('returns default for empty file key', function () {
    expect(ProjectConfig::get('.missing', 'd'))->toBe('d');
});

it('returns default for missing nested key', function () {
    expect(ProjectConfig::get('app.no_such_key_xyz', 'fallback'))->toBe('fallback');
});

it('returns default when the config file is missing', function () {
    expect(ProjectConfig::get('not_a_real_config_file.foo', 'nope'))->toBe('nope');
});

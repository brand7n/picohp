<?php

declare(strict_types=1);

use App\Support\ProjectConfig;

if (!function_exists('config')) {
    /**
     * @param array<string, mixed>|null $default
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        return ProjectConfig::get($key, $default);
    }
}

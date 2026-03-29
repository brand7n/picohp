<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Loads {@code app/config.php} (and optional {@code config/<name>.php}) and resolves keys like {@code app.build_path}.
 */
final class ProjectConfig
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::all();
        }

        $parts = explode('.', $key);
        $fileKey = array_shift($parts);
        if ($fileKey === '') {
            return $default;
        }

        $data = self::loadFile($fileKey);
        foreach ($parts as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            /** @var mixed $data */
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        return self::loadFile('app');
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFile(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $root = dirname(__DIR__, 2);
            $path = $name === 'app'
                ? $root . '/app/config.php'
                : $root . '/config/' . $name . '.php';
            if (!is_file($path)) {
                self::$cache[$name] = [];
            } else {
                $raw = require $path;
                $normalized = [];
                if (is_array($raw)) {
                    foreach ($raw as $k => $v) {
                        if (is_string($k)) {
                            $normalized[$k] = $v;
                        }
                    }
                }
                self::$cache[$name] = $normalized;
            }
        }

        return self::$cache[$name];
    }
}

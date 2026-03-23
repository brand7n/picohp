<?php

declare(strict_types=1);

class Arr
{
    /**
     * @param array<string, mixed> $array
     */
    public static function exists(array $array, string $key): bool
    {
        // TODO: needs array_key_exists or isset support in picoHP
        // For now this provides the correct API signature for self-host testing
        return false;
    }

    /**
     * @param array<mixed> $array
     * @return mixed
     */
    public static function last(array $array)
    {
        $count = count($array);
        if ($count === 0) {
            return null;
        }
        return $array[$count - 1];
    }
}

<?php

declare(strict_types=1);

class Str
{
    /**
     * @param array<string> $needles
     */
    public static function startsWith(string $haystack, array $needles): bool
    {
        /** @var string $needle */
        foreach ($needles as $needle) {
            if (str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}

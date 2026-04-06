<?php

/**
 * picoHP builtin function declarations.
 *
 * Each function signature is parsed at compiler startup to build the BuiltinRegistry.
 * The body must be empty — only the signature matters.
 *
 * Annotations (in PHPDoc):
 *   @runtime-symbol <name>      Override the default runtime symbol (default: pico_<func_name>)
 *   @intrinsic noop              Compiles to nothing (e.g. assert)
 *   @intrinsic type-check        Compile-time type check (is_int, is_string, ...)
 *   @intrinsic identity          Returns arg unchanged (array_reverse, array_merge)
 *   @intrinsic cast-int          intval-style cast
 *   @intrinsic cast-string       strval-style cast
 *   @return-matches-arg 0        Return type is inferred from arg 0 (array_reverse, etc.)
 *   @return-element-type 0       Return type is element type of array arg 0 (end, etc.)
 */

declare(strict_types=1);

// ── String functions ─────────────────────────────────────────────────

/** @runtime-symbol pico_string_len */
function strlen(string $str): int
{
}

/** @runtime-symbol pico_string_substr */
function substr(string $str, int $offset, int $length = 2147483647): string
{
}

/** @runtime-symbol pico_string_trim */
function trim(string $str): string
{
}

/** @runtime-symbol pico_string_repeat */
function str_repeat(string $str, int $times): string
{
}

/** @runtime-symbol pico_string_replace */
function str_replace(string $search, string $replace, string $subject): string
{
}

/** @runtime-symbol pico_string_upper */
function strtoupper(string $str): string
{
}

/** @runtime-symbol pico_string_lower */
function strtolower(string $str): string
{
}

/** @runtime-symbol pico_dechex */
function dechex(int $val): string
{
}

/** @runtime-symbol pico_string_pad */
function str_pad(string $str, int $length, string $padStr = ' ', int $padType = 1): string
{
}

/** @runtime-symbol pico_string_starts_with */
function str_starts_with(string $haystack, string $prefix): bool
{
}

/** @runtime-symbol pico_string_contains */
function str_contains(string $haystack, string $needle): bool
{
}

/** @runtime-symbol pico_implode */
function implode(string $separator, array $pieces): string
{
}

/** @runtime-symbol pico_preg_match */
function preg_match(string $pattern, string $subject, array $matches = []): int
{
}

// ── Array functions ──────────────────────────────────────────────────

/**
 * @runtime-symbol pico_array_len
 */
function count(array $arr): int
{
}

/**
 * @intrinsic array-search
 */
function array_search(int $needle, array $haystack): int
{
}

/**
 * @runtime-symbol pico_array_splice
 */
function array_splice(array $arr, int $offset, int $length): void
{
}

/**
 * @intrinsic array-key-exists
 */
function array_key_exists(mixed $key, array $arr): bool
{
}

/**
 * @intrinsic identity
 * @return-matches-arg 0
 */
function array_reverse(array $arr): array
{
}

/**
 * @intrinsic identity
 * @return-matches-arg 0
 */
function array_merge(array $arr): array
{
}

/**
 * @intrinsic array-pop
 */
function array_pop(array $arr): void
{
}

/** @intrinsic array-shift */
function array_shift(array $arr): void
{
}

/**
 * @return-element-type 0
 * @intrinsic array-end
 */
function end(array $arr): mixed
{
}

// ── Type checking ────────────────────────────────────────────────────

/** @intrinsic type-check */
function is_int(mixed $value): bool
{
}

/** @intrinsic type-check */
function is_string(mixed $value): bool
{
}

/** @intrinsic type-check */
function is_float(mixed $value): bool
{
}

/** @intrinsic type-check */
function is_bool(mixed $value): bool
{
}

// ── Casting ──────────────────────────────────────────────────────────

/** @intrinsic cast-int */
function intval(mixed $val): int
{
}

/** @intrinsic cast-string */
function strval(mixed $val): string
{
}

// ── String utility ───────────────────────────────────────────────────

/** @runtime-symbol pico_substr_count */
function substr_count(string $haystack, string $needle): int
{
}

/** @runtime-symbol pico_string_trim */
function ltrim(string $str): string
{
}

/** @runtime-symbol pico_string_trim */
function rtrim(string $str): string
{
}

// ── I/O & Filesystem ────────────────────────────────────────────────

/** @runtime-symbol pico_fwrite */
function fwrite(int $fd, string $data, int $length = -1): int
{
}

/** @runtime-symbol pico_is_file */
function is_file(string $path): bool
{
}

/** @runtime-symbol pico_is_dir */
function is_dir(string $path): bool
{
}

/** @runtime-symbol pico_file_exists */
function file_exists(string $path): bool
{
}

/** @runtime-symbol pico_file_get_contents */
function file_get_contents(string $path): string
{
}

/** @runtime-symbol pico_realpath */
function realpath(string $path): string
{
}

/** @runtime-symbol pico_mkdir */
function mkdir(string $path, int $permissions = 0777, bool $recursive = false): bool
{
}

/** @runtime-symbol pico_file_put_contents */
function file_put_contents(string $filename, string $data): int
{
}

/** @runtime-symbol pico_ord */
function ord(string $str): int
{
}

/** @runtime-symbol pico_getenv */
function getenv(string $name): string
{
}

/** @runtime-symbol pico_dirname */
function dirname(string $path, int $levels = 1): string
{
}

function max(int $a, int $b): int
{
}

/** @runtime-symbol pico_array_slice */
function array_slice(array $arr, int $offset, int $length = -1): array
{
}

/** @intrinsic noop */
function class_alias(string $original, string $alias): void
{
}

/** @intrinsic noop */
function debug_backtrace(int $options = 0, int $limit = 0): array
{
}

// ── Search & compare ────────────────────────────────────────────────

/** @runtime-symbol pico_in_array_int */
function in_array(mixed $needle, array $haystack, bool $strict = false): bool
{
}

/** @runtime-symbol pico_strpos */
function strpos(string $haystack, string $needle, int $offset = 0): int
{
}

/** @runtime-symbol pico_substr_compare */
function substr_compare(string $haystack, string $needle, int $offset, int $length = 2147483647, bool $case_insensitive = false): int
{
}

// ── Formatting ──────────────────────────────────────────────────────

/** @runtime-symbol pico_sprintf */
function sprintf(string $format, mixed ...$args): string
{
}

/** @runtime-symbol pico_json_encode */
function json_encode(mixed $value): string
{
}

/** @runtime-symbol pico_preg_quote */
function preg_quote(string $str, string $delimiter = ''): string
{
}

// ── Class/type checks ───────────────────────────────────────────────

/** @intrinsic noop */
function class_exists(string $class, bool $autoload = true): bool
{
}

// ── Process & I/O ───────────────────────────────────────────────────

/** @runtime-symbol pico_exec */
function exec(string $command, int $result_code = 0): string
{
}

/** @runtime-symbol pico_fopen */
function fopen(string $filename, string $mode): int
{
}

/** @runtime-symbol pico_fclose */
function fclose(int $fd): bool
{
}

/** @runtime-symbol pico_constant */
function constant(string $name): int
{
}

// ── Misc ─────────────────────────────────────────────────────────────

/** @intrinsic noop */
function assert(bool $condition): void
{
}

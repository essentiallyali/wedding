<?php
/**
 * Small compatibility shim.
 *
 * Shared hosting is often pinned to an older PHP than a laptop runs, and the
 * account owner may not know which. Rather than demand a specific version and
 * fail with a blank 500 — which is what happened the first time this shipped —
 * the code targets PHP 7.4 and fills in the few newer helpers it wants.
 */

declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

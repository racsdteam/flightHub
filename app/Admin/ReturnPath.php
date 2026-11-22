<?php

namespace App\Admin;

/**
 * The page a user was on when the session ended (public/js/hub.js sends it to the sign-in page
 * as ?return=). Only a same-origin path is accepted: one leading slash, no scheme or host, no
 * backslash or control character, not a sign-in or Livewire address. Anything else: null.
 */
final class ReturnPath
{
    private const REFUSED_PREFIXES = ['/login', '/logout', '/two-factor', '/livewire'];

    public static function safe(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 2000) {
            return null;
        }
        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        foreach (self::REFUSED_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($parts['path'] ?? ''), $prefix)) {
                return null;
            }
        }

        return $path;
    }
}

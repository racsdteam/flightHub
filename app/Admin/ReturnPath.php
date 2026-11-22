<?php

namespace App\Admin;

/**
 * The page a user was on when the session ended (public/js/hub.js sends it to the sign-in page
 * as ?return=). Only a same-origin path is accepted: one leading slash, no scheme or host, no
 * backslash or control character, not a sign-in or Livewire address. Anything else: null.
 *
 * Under a base path (D-061, hub.base_path, e.g. /flighthub) the browser's path starts with it:
 * only a path under the base path belongs to the hub (another app on the same host, /pulse/…,
 * or / is refused). The base path is stripped before the checks and the path returned is the
 * hub's own (url() adds the base path again), so the redirect never doubles into
 * /flighthub/flighthub.
 */
final class ReturnPath
{
    private const REFUSED_PREFIXES = ['/login', '/logout', '/two-factor', '/livewire'];

    public static function safe(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 2000) {
            return null;
        }
        $path = self::withinBasePath($path);
        if ($path === null) {
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

    /**
     * The path without the base path; null when it is not under the base path, or when what
     * remains starts with the base path again (no page of the hub lives there).
     */
    private static function withinBasePath(string $path): ?string
    {
        $base = (string) config('hub.base_path');
        if ($base === '') {
            return $path;
        }
        if (! str_starts_with($path, $base)) {
            return null;
        }
        $rest = substr($path, strlen($base));
        if ($rest === '' || $rest[0] === '?') {
            $rest = '/'.$rest;
        } elseif ($rest[0] !== '/') {
            return null;
        }
        if ($rest === $base || str_starts_with($rest, $base.'/') || str_starts_with($rest, $base.'?')) {
            return null;
        }

        return $rest;
    }
}

<?php

namespace App\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The hub's own API keys (no Sanctum, no Passport). Format
 * "fdh_" + 8-char prefix + "_" + 40 random base62 characters. Only the SHA-256
 * of the whole key is stored; the key itself is returned once, at issue, and
 * never logged.
 */
final class ApiKeys
{
    private const PATTERN = '/^(fdh_[0-9A-Za-z]{8})_[0-9A-Za-z]{32,128}$/';

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * @return array{key: string, prefix: string, id: int}
     */
    public static function issue(int $consumerId, string $label, ?CarbonImmutable $expiresAt, string $by): array
    {
        do {
            $prefix = 'fdh_'.self::random(8);
        } while (DB::table('api_keys')->where('prefix', $prefix)->exists());

        $key = $prefix.'_'.self::random(40);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $id = DB::table('api_keys')->insertGetId([
            'consumer_id' => $consumerId,
            'prefix' => $prefix,
            'secret_hash' => self::hash($key),
            'label' => $label,
            'created_by' => $by,
            'expires_at' => $expiresAt?->utc()->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['key' => $key, 'prefix' => $prefix, 'id' => $id];
    }

    public static function hash(#[\SensitiveParameter] string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * The key and its consumer for an Authorization header, or null for every
     * refusal (missing, malformed, unknown, wrong, revoked, expired, inactive
     * consumer) alike. The hash comparison is constant-time, and runs even for
     * an unknown prefix.
     *
     * @return array{key: object, consumer: object}|null
     */
    public static function authenticate(#[\SensitiveParameter] ?string $authorization): ?array
    {
        if ($authorization === null || ! preg_match('/^Bearer ([^\s]+)$/', $authorization, $m)
            || ! preg_match(self::PATTERN, $m[1], $parts)) {
            return null;
        }

        $row = DB::table('api_keys')->where('prefix', $parts[1])->first();
        $matches = hash_equals($row->secret_hash ?? str_repeat('0', 64), self::hash($m[1]));
        if ($row === null || ! $matches || $row->revoked_at !== null) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        if ($row->expires_at !== null && CarbonImmutable::parse($row->expires_at, 'UTC')->lte($now)) {
            return null;
        }

        $consumer = DB::table('consumers')->where('id', $row->consumer_id)->first();
        if ($consumer === null || ! $consumer->active) {
            return null;
        }

        // At most once a minute.
        if ($row->last_used_at === null || CarbonImmutable::parse($row->last_used_at, 'UTC')->lt($now->subMinute())) {
            DB::table('api_keys')->where('id', $row->id)->update(['last_used_at' => $now->format('Y-m-d H:i:s')]);
        }

        return ['key' => $row, 'consumer' => $consumer];
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 61)];
        }

        return $out;
    }
}

<?php

namespace App\Aodb;

use JsonException;
use stdClass;

/**
 * The result of one AODB call. Holds no credentials, no token, no headers and,
 * for non-2xx answers, no body: error is "<status> <ERROR_KEY>" only, never
 * the server's message (it can echo request details).
 */
final class AodbResponse
{
    public const OK = 'ok';

    public const INVALID_PAYLOAD = 'invalid_payload';

    public const HTTP_ERROR = 'http_error';

    public const TIMEOUT = 'timeout';

    public const AUTH_ERROR = 'auth_error';

    public const LOGIN_BACKOFF = 'login_backoff';

    public const FORBIDDEN = 'forbidden';

    public const CONFIG_ERROR = 'config_error';

    /**
     * @param  list<stdClass>|null  $flights  the "flights" array (each entry as decoded); null unless outcome ok
     * @param  mixed  $payload  the whole decoded body (2xx and JSON only), for the canonical hash
     */
    private function __construct(
        public readonly string $outcome,
        public readonly string $endpoint,
        public readonly ?int $httpStatus = null,
        public readonly ?string $body = null,
        public readonly ?array $flights = null,
        public readonly ?string $error = null,
        public readonly mixed $payload = null,
    ) {}

    /**
     * A 2xx answer: ok when the body is JSON with a "flights" array, else
     * invalid_payload. The body is kept either way.
     */
    public static function fromSuccess(string $endpoint, int $status, string $body): self
    {
        try {
            $payload = CanonicalJson::decode($body);
        } catch (JsonException) {
            return new self(self::INVALID_PAYLOAD, $endpoint, $status, $body, error: "{$status} UNPARSABLE_BODY");
        }

        if (! $payload instanceof stdClass || ! isset($payload->flights) || ! is_array($payload->flights)) {
            return new self(self::INVALID_PAYLOAD, $endpoint, $status, $body, error: "{$status} NO_FLIGHTS_ARRAY", payload: $payload);
        }

        return new self(self::OK, $endpoint, $status, $body, array_values($payload->flights), payload: $payload);
    }

    /**
     * A non-2xx answer: the body is reduced to its documented error key.
     */
    public static function fromFailure(string $outcome, string $endpoint, int $status, ?string $body): self
    {
        return new self($outcome, $endpoint, $status, error: self::summary($status, $body));
    }

    public static function failed(string $outcome, string $endpoint, string $error): self
    {
        return new self($outcome, $endpoint, error: $error);
    }

    public static function errorKey(?string $body): ?string
    {
        $decoded = json_decode((string) $body, true);
        $key = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_string($key) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key) ? $key : null;
    }

    private static function summary(int $status, ?string $body): string
    {
        $key = self::errorKey($body);

        return $key === null ? (string) $status : "{$status} {$key}";
    }

    public function isOk(): bool
    {
        return $this->outcome === self::OK;
    }

    /**
     * 2xx with a body: stored as a raw response even when it does not parse.
     */
    public function hasBody(): bool
    {
        return $this->body !== null;
    }
}

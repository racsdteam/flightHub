<?php

namespace App\Aodb;

use App\Flights\Window;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use SensitiveParameterValue;
use Throwable;

/**
 * Airport Keeper External API v3.2.5 over HTTPS.
 *
 * Never logs, prints, stores unencrypted or puts in an exception message the
 * password, the token, an Authorization header or any /login body (R9). The
 * token lives in the cache store encrypted with the app key. Parameters that
 * carry the token or the config are #[\SensitiveParameter]: stack traces show
 * a placeholder instead.
 *
 * HTTP 429 on any call (login included) pauses every request until
 * Retry-After, or for 5 minutes without one (rate limiting is not documented).
 */
final class HttpAodbClient implements AodbClient
{
    public const TOKEN_KEY = 'aodb.token';

    public const BACKOFF_KEY = 'aodb.login_backoff_until';

    public const RATE_LIMIT_KEY = 'aodb.rate_limited_until';

    private const RATE_LIMIT_DEFAULT_SECONDS = 300;

    /** 401 keys after which one re-login and one retry are tried. */
    private const RELOGIN_KEYS = ['TOKEN_EXPIRED', 'NO_TOKEN', 'USER_NOT_LOGGED', 'WRONG_TOKEN_FORMAT'];

    private const AIRPORT_PATH = '/flights/v1/airport';

    private const BYID_PATH = '/flights/v1/byid';

    private const LOGIN_PATH = '/login';

    /**
     * @param  array<string, mixed>  $config  config('aodb')
     */
    public function __construct(#[\SensitiveParameter] private readonly array $config) {}

    public function flightsAtAirport(Window $window): AodbResponse
    {
        return $this->get(self::AIRPORT_PATH, [
            'airport' => $this->config['airport'],
            'from' => $window->from->format('Y-m-d\TH:i:s\Z'),
            'to' => $window->to->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function flightsById(array $ids): AodbResponse
    {
        return $this->get(self::BYID_PATH, [
            'airport' => $this->config['airport'],
            'ids' => implode(',', $ids),
        ]);
    }

    private function get(string $path, array $query): AodbResponse
    {
        if (($problem = $this->configProblem()) !== null) {
            return AodbResponse::failed(AodbResponse::CONFIG_ERROR, $path, $problem);
        }

        if ($this->rateLimited()) {
            return AodbResponse::failed(AodbResponse::RATE_LIMITED, $path, 'rate_limited');
        }

        $token = $this->cachedToken();
        if ($token === null) {
            $token = $this->login();
            if ($token instanceof AodbResponse) {
                return $token;
            }
        }

        $response = $this->send($path, $query, $token);

        if ($response instanceof Response && $response->status() === 429) {
            return $this->rateLimit($path, $response);
        }

        if ($response instanceof Response && $response->status() === 401
            && in_array(AodbResponse::errorKey($response->body()), self::RELOGIN_KEYS, true)) {
            Cache::forget(self::TOKEN_KEY);
            $token = $this->login();
            if ($token instanceof AodbResponse) {
                return $token;
            }
            $response = $this->send($path, $query, $token);
            if ($response instanceof Response && $response->status() === 429) {
                return $this->rateLimit($path, $response);
            }
        }

        if ($response instanceof AodbResponse) {
            return $response;
        }

        return match (true) {
            $response->successful() => AodbResponse::fromSuccess($path, $response->status(), $response->body()),
            $response->status() === 401 => AodbResponse::fromFailure(AodbResponse::AUTH_ERROR, $path, 401, $response->body()),
            $response->status() === 403 => AodbResponse::fromFailure(AodbResponse::FORBIDDEN, $path, 403, $response->body()),
            default => AodbResponse::fromFailure(AodbResponse::HTTP_ERROR, $path, $response->status(), $response->body()),
        };
    }

    private function send(string $path, array $query, #[\SensitiveParameter] string $token): Response|AodbResponse
    {
        // Not withToken(): that puts the header in Guzzle's options array, which
        // a stack trace records. The header is set on the request object instead,
        // from a SensitiveParameterValue that dumps as empty.
        $secret = new SensitiveParameterValue($token);

        try {
            return $this->http()
                ->withRequestMiddleware(fn (RequestInterface $request) => $request->withHeader('Authorization', 'Bearer '.$secret->getValue()))
                ->get($path, $query);
        } catch (ConnectionException $e) {
            return $this->connectionFailure($path, $e);
        }
    }

    /**
     * @return string|AodbResponse the token, or the failure
     */
    private function login(): string|AodbResponse
    {
        if ($this->rateLimited()) {
            return AodbResponse::failed(AodbResponse::RATE_LIMITED, self::LOGIN_PATH, 'rate_limited');
        }

        $until = Cache::get(self::BACKOFF_KEY);
        if ($until !== null && now()->getTimestamp() < (int) $until) {
            return AodbResponse::failed(AodbResponse::LOGIN_BACKOFF, self::LOGIN_PATH, 'login_backoff');
        }

        try {
            $response = $this->http()->asJson()->post(self::LOGIN_PATH, [
                'username' => (string) $this->config['username'],
                'password' => (string) $this->config['password'],
            ]);
        } catch (ConnectionException $e) {
            return $this->connectionFailure(self::LOGIN_PATH, $e);
        }

        // Rate limited: a pause, not a failed login (no login back-off).
        if ($response->status() === 429) {
            return $this->rateLimit(self::LOGIN_PATH, $response);
        }

        if (in_array($response->status(), [400, 401], true)) {
            $minutes = (int) $this->config['login_backoff_minutes'];
            $until = now()->addMinutes($minutes)->getTimestamp();
            Cache::put(self::BACKOFF_KEY, $until, now()->addMinutes($minutes));

            return AodbResponse::fromFailure(AodbResponse::AUTH_ERROR, self::LOGIN_PATH, $response->status(), $response->body());
        }

        if (! $response->successful()) {
            return AodbResponse::fromFailure(AodbResponse::HTTP_ERROR, self::LOGIN_PATH, $response->status(), $response->body());
        }

        // The token is in the RESPONSE header Authorization, with or without "Bearer ".
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', trim($response->header('Authorization'))));
        if ($token === '') {
            return AodbResponse::failed(AodbResponse::AUTH_ERROR, self::LOGIN_PATH, $response->status().' NO_TOKEN_IN_RESPONSE');
        }

        $ttl = $this->tokenLifetime($token);
        if ($ttl > 0) {
            Cache::put(self::TOKEN_KEY, Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    private function cachedToken(): ?string
    {
        $stored = Cache::get(self::TOKEN_KEY);
        if (! is_string($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            Cache::forget(self::TOKEN_KEY);

            return null;
        }
    }

    /**
     * Seconds to keep the token: the JWT's exp minus 60 s, read without
     * verifying the signature; unreadable → 30 minutes.
     */
    private function tokenLifetime(#[\SensitiveParameter] string $token): int
    {
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
            $claims = $json === false ? null : json_decode($json, true);
            if (is_array($claims) && is_int($claims['exp'] ?? null)) {
                return $claims['exp'] - 60 - now()->getTimestamp();
            }
        }

        return 30 * 60;
    }

    private function rateLimited(): bool
    {
        $until = Cache::get(self::RATE_LIMIT_KEY);

        return $until !== null && now()->getTimestamp() < (int) $until;
    }

    /**
     * Pause every request until Retry-After (seconds or an HTTP date), else 5 minutes.
     */
    private function rateLimit(string $path, Response $response): AodbResponse
    {
        $now = now()->getTimestamp();
        $retryAfter = trim($response->header('Retry-After'));
        $until = $now + self::RATE_LIMIT_DEFAULT_SECONDS;

        if (preg_match('/^\d{1,10}$/', $retryAfter)) {
            $until = $now + (int) $retryAfter;
        } elseif ($retryAfter !== '') {
            $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter);
            if ($date !== false) {
                $until = $date->getTimestamp();
            }
        }

        if ($until > $now) {
            Cache::put(self::RATE_LIMIT_KEY, $until, $until - $now);
        }

        return AodbResponse::fromFailure(AodbResponse::RATE_LIMITED, $path, 429, $response->body());
    }

    private function connectionFailure(string $path, ConnectionException $e): AodbResponse
    {
        // The exception message names the URL; only its kind is kept.
        $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;

        return $timedOut
            ? AodbResponse::failed(AodbResponse::TIMEOUT, $path, 'timeout')
            : AodbResponse::failed(AodbResponse::HTTP_ERROR, $path, 'connection_failed');
    }

    private function configProblem(): ?string
    {
        if (blank($this->config['username'] ?? null) || blank($this->config['password'] ?? null)) {
            return 'AODB_USERNAME or AODB_PASSWORD missing';
        }

        $url = parse_url((string) ($this->config['base_url'] ?? ''));
        if (($url['scheme'] ?? null) !== 'https' || blank($url['host'] ?? null)) {
            return 'AODB_BASE_URL must be an https URL';
        }

        if (! preg_match('/^[A-Z]{4}$/', (string) ($this->config['airport'] ?? ''))) {
            return 'AODB_AIRPORT must be a 4-letter ICAO code';
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->connectTimeout((int) $this->config['connect_timeout'])
            ->timeout((int) $this->config['timeout'])
            ->acceptJson();
    }
}

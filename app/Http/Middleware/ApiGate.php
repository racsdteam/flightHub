<?php

namespace App\Http\Middleware;

use App\Api\ApiException;
use App\Api\ApiKeys;
use App\Api\ApiResponses;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api/v1 call: key check (Authorization: Bearer only), rate limits,
 * and one api_calls row once the response is known, refused calls included.
 * Never logs the key or the Authorization header.
 *
 * HTTPS only (D-062): in production a call over plain HTTP gets 403 HTTPS_REQUIRED before the
 * key is read or looked up, and is never redirected (a client would follow the redirect and keep
 * sending its key in clear). It is logged as an unauthenticated call. "Over HTTPS" is what
 * public/.htaccess's redirect rule accepts: HTTPS on, or X-Forwarded-SSL: on.
 */
class ApiGate
{
    /** Query parameters copied to api_calls.params; values cut to 100 chars. */
    private const LOGGED_PARAMS = ['from', 'to', 'day', 'as_of', 'cursor', 'limit', 'flow', 'history', 'include_removed'];

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $at = CarbonImmutable::now('UTC');
        $auth = null;

        try {
            if (app()->environment('production') && ! self::overHttps($request)) {
                throw new ApiException(403, 'HTTPS_REQUIRED', 'HTTPS required.');
            }

            // The key first: a valid key is never blocked by the IP limit.
            $auth = ApiKeys::authenticate($request->header('Authorization'));
            if ($auth === null) {
                // Only refused calls count toward the per-IP limit; beyond it they get 429.
                $ipKey = 'api-refused:'.$request->ip();
                if (RateLimiter::tooManyAttempts($ipKey, (int) config('api_fields.refused_per_ip_per_minute'))) {
                    throw self::rateLimited(RateLimiter::availableIn($ipKey));
                }
                RateLimiter::hit($ipKey, 60);
                throw new ApiException(401, 'UNAUTHORIZED', 'A valid API key is required.');
            }

            $keyLimit = 'api-key:'.$auth['key']->id;
            if (RateLimiter::tooManyAttempts($keyLimit, max(1, (int) $auth['consumer']->rate_limit_per_minute))) {
                throw self::rateLimited(RateLimiter::availableIn($keyLimit));
            }
            RateLimiter::hit($keyLimit, 60);

            $request->attributes->set('api.consumer', $auth['consumer']);
            $response = $next($request);
        } catch (ApiException $e) {
            $response = ApiResponses::error($e);
        }

        $this->log($request, $response, $auth, $at, $started);

        return $response;
    }

    private static function overHttps(Request $request): bool
    {
        return $request->isSecure() || strtolower(trim((string) $request->header('X-Forwarded-SSL'))) === 'on';
    }

    private static function rateLimited(int $seconds): ApiException
    {
        return new ApiException(429, 'RATE_LIMITED', 'Too many requests.', ['Retry-After' => (string) max(1, $seconds)]);
    }

    private function log(Request $request, Response $response, ?array $auth, CarbonImmutable $at, int $started): void
    {
        $params = [];
        foreach (self::LOGGED_PARAMS as $name) {
            $value = $request->query($name);
            if (is_scalar($value)) {
                $params[$name] = mb_substr((string) $value, 0, 100);
            }
        }

        $errorCode = null;
        if ($response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $errorCode = is_array($body) && is_string($body['error'] ?? null) ? mb_substr($body['error'], 0, 32) : null;
        }

        $rows = $request->attributes->get('api.rows');

        DB::table('api_calls')->insert([
            'at' => $at->format('Y-m-d H:i:s.u'),
            'consumer_id' => $auth['consumer']->id ?? null,
            'api_key_id' => $auth['key']->id ?? null,
            'method' => $request->method(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'params' => $params === [] ? null : json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => $response->getStatusCode(),
            'rows' => is_int($rows) ? $rows : null,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'ip' => mb_substr((string) $request->ip(), 0, 45),
            'error_code' => $errorCode,
        ]);
    }
}

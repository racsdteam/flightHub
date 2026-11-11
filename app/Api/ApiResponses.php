<?php

namespace App\Api;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every API error is {"error": CODE, "message": text}; never a stack trace,
 * whatever APP_DEBUG says.
 */
final class ApiResponses
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function error(ApiException $e): JsonResponse
    {
        return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status, $e->headers, self::FLAGS);
    }

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return self::error(match (true) {
            $e instanceof ApiException => $e,
            $e instanceof NotFoundHttpException => ApiException::notFound(),
            $e instanceof MethodNotAllowedHttpException => new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.'),
            $e instanceof HttpExceptionInterface => new ApiException($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), 'Request refused.'),
            default => new ApiException(500, 'SERVER_ERROR', 'Internal error.'),
        });
    }

    /**
     * @param  array<string, mixed>  $extra  more top-level members (e.g. history)
     */
    public static function data(mixed $data, array $meta, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $data] + $extra + ['meta' => (object) $meta], 200, [], self::FLAGS);
    }

    /**
     * asOf = observed time of the last ok poll; feed "stale" when it is older
     * than 15 minutes, or when there is none (D-037).
     *
     * @return array{asOf: ?string, feed: string}
     */
    public static function feed(): array
    {
        $last = DB::table('polls')->where('outcome', 'ok')->orderByDesc('id')->value('finished_at');
        $at = $last === null ? null : CarbonImmutable::parse($last, 'UTC');
        $stale = $at === null || $at->lt(CarbonImmutable::now('UTC')->subMinutes((int) config('api_fields.stale_after_minutes')));

        return [
            'asOf' => $at?->format('Y-m-d\TH:i:s\Z'),
            'feed' => $stale ? 'stale' : 'ok',
        ];
    }
}

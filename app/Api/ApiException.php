<?php

namespace App\Api;

use RuntimeException;

/**
 * An API error: rendered as {"error": CODE, "message": text} with its status.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(422, 'INVALID_PARAMETER', $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }
}

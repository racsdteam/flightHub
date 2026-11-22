<?php

namespace Tests\Feature\ApiDocs;

use App\Http\Controllers\ApiDocsController;
use Opis\JsonSchema\CompliantValidator;

/**
 * The PUBLIC OpenAPI document (D-054), the one served at /api/openapi.yaml.
 */
final class PublicOpenApi extends OpenApi
{
    public const FILE = ApiDocsController::DOCUMENT;

    public const ID = 'https://flighthub.invalid/api/openapi.yaml';

    protected static ?object $document = null;

    /** @var array<string, mixed>|null */
    protected static ?array $tree = null;

    protected static ?CompliantValidator $validator = null;
}

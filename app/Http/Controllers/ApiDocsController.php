<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The API documentation (D-051): public and read-only, no sign-in, no session.
 * /api/docs shows Swagger UI (served by the hub) for /api/openapi.yaml; calling
 * the API from it still needs a consumer key.
 */
class ApiDocsController
{
    public const DOCUMENT = 'resources/api/flighthub-v1.openapi.yaml';

    public function page(): Response
    {
        return response()->view('api-docs');
    }

    public function document(): Response
    {
        return response((string) file_get_contents(base_path(self::DOCUMENT)), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }
}

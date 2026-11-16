<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The API documentation (D-051, D-054): public and read-only, no sign-in, no session.
 * /api/docs shows Swagger UI (served by the hub) for /api/openapi.yaml, the short
 * PUBLIC document, with one server added here: config app.url + /api/v1 (so Try it out
 * calls the hub that serves the page). The complete INTERNAL document is not served;
 * it stays in the repository for the contract tests and RAC IT.
 */
class ApiDocsController
{
    public const DOCUMENT = 'resources/api/public-v1.openapi.yaml';

    public const INTERNAL_DOCUMENT = 'resources/api/flighthub-v1.openapi.yaml';

    public function page(): Response
    {
        return response()->view('api-docs');
    }

    public function document(): Response
    {
        return response(self::served(), 200, ['Content-Type' => 'application/yaml; charset=UTF-8']);
    }

    /** The public document with its one server, the hub's own API address. */
    public static function served(): string
    {
        $yaml = (string) file_get_contents(base_path(self::DOCUMENT));
        $server = "servers:\n  - url: ".json_encode(rtrim((string) config('app.url'), '/').'/api/v1', JSON_UNESCAPED_SLASHES)."\n";

        return preg_replace('/^paths:$/m', $server.'paths:', $yaml, 1);
    }
}

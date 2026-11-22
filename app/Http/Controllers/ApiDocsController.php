<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The API documentation (D-051, D-054): public and read-only, no sign-in, no session.
 * /api/docs shows Swagger UI (served by the hub) for /api/openapi.yaml, the short
 * PUBLIC document, with one server added here: config app.url + /api/v1 (so Try it out
 * calls the hub that serves the page). The complete INTERNAL document is not served;
 * it stays in the repository for the contract tests and RAC IT, and gets the same one
 * server when read through withServer(). Neither file holds an address (D-061).
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
        return self::withServer(self::DOCUMENT);
    }

    /**
     * A document (public or internal) with servers[0] = APP_URL + /api/v1, set here when it is
     * read, never written in the file: the address changes when the hub moves (D-061).
     */
    public static function withServer(string $document): string
    {
        $yaml = (string) file_get_contents(base_path($document));
        $server = "servers:\n  - url: ".json_encode(rtrim((string) config('app.url'), '/').'/api/v1', JSON_UNESCAPED_SLASHES)."\n";

        return preg_replace('/^paths:$/m', $server.'paths:', $yaml, 1);
    }
}

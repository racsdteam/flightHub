<?php

namespace App\Aodb;

use App\Flights\Window;
use Closure;
use RuntimeException;

/**
 * Serves recorded AODB answers from tests/Fixtures/aodb/ (AODB_CLIENT=fixture,
 * never in production) and scripted answers in tests. No network.
 *
 * flightsAtAirport: the queued answers in order, then the newest fixture file.
 * flightsById: the scripted answer, else the requested ids found in the
 * current fixture file.
 */
final class FixtureAodbClient implements AodbClient
{
    /** @var list<string|AodbResponse> */
    private array $airportQueue = [];

    private ?Closure $byIdScript = null;

    /** @var list<list<string>> ids of every flightsById call, for tests */
    public array $byIdCalls = [];

    public function __construct(private readonly string $directory) {}

    /**
     * Queue a body (served as HTTP 200) or a ready response for the next
     * flightsAtAirport call.
     */
    public function queueAirport(string|AodbResponse $answer): self
    {
        $this->airportQueue[] = $answer;

        return $this;
    }

    /**
     * @param  Closure(list<string>): (list<object>|AodbResponse)  $script  flights to return, or a response
     */
    public function scriptById(Closure $script): self
    {
        $this->byIdScript = $script;

        return $this;
    }

    public function flightsAtAirport(Window $window): AodbResponse
    {
        $answer = array_shift($this->airportQueue) ?? $this->newestFile();

        return $answer instanceof AodbResponse
            ? $answer
            : AodbResponse::fromSuccess('/flights/v1/airport', 200, $answer);
    }

    public function flightsById(array $ids): AodbResponse
    {
        $this->byIdCalls[] = $ids;

        if ($this->byIdScript !== null) {
            $answer = ($this->byIdScript)($ids);
            if ($answer instanceof AodbResponse) {
                return $answer;
            }
            $flights = $answer;
        } else {
            $payload = CanonicalJson::decode($this->newestFile());
            $flights = array_values(array_filter(
                $payload->flights ?? [],
                fn ($flight) => in_array($flight->id ?? null, $ids, true),
            ));
        }

        return AodbResponse::fromSuccess('/flights/v1/byid', 200, json_encode(['flights' => $flights], JSON_UNESCAPED_SLASHES));
    }

    private function newestFile(): string
    {
        $files = glob(rtrim($this->directory, '/').'/*.json') ?: [];
        sort($files);
        $file = end($files);

        if ($file === false) {
            throw new RuntimeException('No AODB fixture file in tests/Fixtures/aodb/.');
        }

        return (string) file_get_contents($file);
    }
}

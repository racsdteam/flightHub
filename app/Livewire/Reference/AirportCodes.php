<?php

namespace App\Livewire\Reference;

use Illuminate\Validation\Rule;

/**
 * airport_code_corrections (D-006, D-028): an AODB adepIata / adesIata value
 * and the IATA / ICAO codes served instead.
 */
class AirportCodes extends ReferenceTable
{
    protected function table(): string
    {
        return 'airport_code_corrections';
    }

    public function title(): string
    {
        return 'Airport code corrections';
    }

    protected function columns(): array
    {
        return [
            'aodb_value' => ['label' => 'AODB value', 'hint' => 'as received in adepIata / adesIata, e.g. NB0'],
            'corrected_iata' => ['label' => 'Served IATA', 'hint' => '3 letters'],
            'corrected_icao' => ['label' => 'Served ICAO', 'hint' => '4 letters; fills a missing ICAO code'],
            'reason' => ['label' => 'Reason'],
        ];
    }

    protected function normalise(array $data): array
    {
        foreach (['corrected_iata', 'corrected_icao'] as $code) {
            $data[$code] = $data[$code] === null ? null : mb_strtoupper($data[$code]);
        }

        return $data;
    }

    protected function rules(): array
    {
        return [
            'form.aodb_value' => ['required', 'string', 'max:16', 'regex:/^\S+$/', Rule::unique('airport_code_corrections', 'aodb_value')->ignore($this->editing ?: null)],
            'form.corrected_iata' => ['required', 'regex:/^[A-Z]{3}$/'],
            'form.corrected_icao' => ['nullable', 'regex:/^[A-Z]{4}$/'],
            'form.reason' => ['required', 'string', 'max:255'],
        ];
    }
}

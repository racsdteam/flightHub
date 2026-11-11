<?php

namespace App\Livewire\Reference;

use Illuminate\Validation\Rule;

/**
 * aircraft_types (D-033): fills a missing ICAO type, type name and wake
 * category for (airline, IATA type); '*' = any airline. Capacity never.
 */
class AircraftTypes extends ReferenceTable
{
    protected function table(): string
    {
        return 'aircraft_types';
    }

    public function title(): string
    {
        return 'Aircraft types';
    }

    protected function columns(): array
    {
        return [
            'airline_iata' => ['label' => 'Airline', 'hint' => 'IATA code, or * for any airline'],
            'ac_type_iata' => ['label' => 'IATA type', 'hint' => '3–4 letters / digits, e.g. DH8'],
            'ac_type_icao' => ['label' => 'ICAO type', 'hint' => '3–4 letters / digits, e.g. DH8D'],
            'type_name' => ['label' => 'Type name'],
            'wtc' => ['label' => 'Wake category', 'type' => 'select', 'options' => ['J', 'H', 'M', 'L']],
            'min_turnaround_minutes' => ['label' => 'Min. turnaround (min)', 'type' => 'number'],
        ];
    }

    protected function normalise(array $data): array
    {
        foreach (['airline_iata', 'ac_type_iata', 'ac_type_icao', 'wtc'] as $code) {
            $data[$code] = $data[$code] === null ? null : mb_strtoupper($data[$code]);
        }

        return $data;
    }

    protected function rules(): array
    {
        return [
            // The spec's airlineIata pattern, anchored as a whole.
            'form.airline_iata' => ['required', 'regex:/^(\*|[A-Z]{2}|[A-Z]\d|\d[A-Z])$/'],
            'form.ac_type_iata' => ['required', 'regex:/^[A-Z\d]{3,4}$/', Rule::unique('aircraft_types', 'ac_type_iata')
                ->where('airline_iata', $this->form['airline_iata'] ?? '')->ignore($this->editing ?: null)],
            'form.ac_type_icao' => ['nullable', 'regex:/^[A-Z\d]{3,4}$/'],
            'form.type_name' => ['nullable', 'string', 'max:64'],
            'form.wtc' => ['nullable', 'in:J,H,M,L'],
            'form.min_turnaround_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }
}

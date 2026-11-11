<?php

namespace App\Livewire\Reference;

use Illuminate\Validation\Rule;

/**
 * stands: the reviewed list of KIA stands; stand_unknown flags a pkg not in it
 * (active only while at least one row is active).
 */
class Stands extends ReferenceTable
{
    protected function table(): string
    {
        return 'stands';
    }

    public function title(): string
    {
        return 'Stands';
    }

    protected function columns(): array
    {
        return [
            'code' => ['label' => 'Stand', 'hint' => 'as the AODB writes pkg, e.g. A2B'],
            'remarks' => ['label' => 'Remarks'],
        ];
    }

    protected function normalise(array $data): array
    {
        $data['code'] = $data['code'] === null ? null : mb_strtoupper($data['code']);

        return $data;
    }

    protected function rules(): array
    {
        return [
            'form.code' => ['required', 'regex:/^[A-Z0-9-]{1,16}$/', Rule::unique('stands', 'code')->ignore($this->editing ?: null)],
            'form.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}

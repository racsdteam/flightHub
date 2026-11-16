<?php

namespace App\Livewire\Quality;

use App\Quality\FlagResolver;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolving a quality flag from a screen (flag page, quality list): manage-quality, the
 * actor is the signed-in user, a note is required for ignored and reported, FlagResolver
 * does the write and the audit.
 */
trait ResolvesFlags
{
    public string $note = '';

    /** The resolution asked for (null = remove), or null when it is not one a person may set here. */
    protected function askedResolution(string $resolution): array
    {
        $value = $resolution === 'none' ? null : $resolution;

        return [in_array($value, ['fixed', 'ignored', 'reported', null], true), $value];
    }

    protected function noteMissing(?string $resolution): bool
    {
        return in_array($resolution, ['ignored', 'reported'], true) && trim($this->note) === '';
    }

    protected function applyResolution(int $flagId, ?string $resolution): bool
    {
        Gate::authorize('manage-quality');
        try {
            (new FlagResolver)->resolve($flagId, $resolution, (string) auth()->user()->email, trim($this->note) === '' ? null : trim($this->note));
            $this->note = '';
            session()->flash('status', $resolution === null ? 'Resolution removed.' : "Resolution set: {$resolution}.");

            return true;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->addError('note', $e->getMessage());

            return false;
        }
    }
}

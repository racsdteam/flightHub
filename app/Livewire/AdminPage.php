<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * A full-page admin screen. Its ability is checked on every Livewire request
 * (mount and every update), server-side, deny-by-default; actions that need
 * more check their own ability.
 */
abstract class AdminPage extends Component
{
    abstract protected function ability(): string;

    public function boot(): void
    {
        Gate::authorize($this->ability());
    }

    public function paginationView(): string
    {
        return 'components.pager';
    }

    public function paginationSimpleView(): string
    {
        return 'components.pager';
    }
}

<?php

namespace App\Http\Controllers\Admin;

use Illuminate\View\View;

/**
 * The first screen after sign-in: feed health (Livewire, refreshes every 60 s).
 */
class DashboardController
{
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}

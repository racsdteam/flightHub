<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\TwoFactorEnrolmentController;
use App\Livewire\AuditLog;
use App\Livewire\Consumers\ConsumerList;
use App\Livewire\Consumers\ConsumerPage;
use App\Livewire\Consumers\ConsumerUsage;
use App\Livewire\Flights\DayView;
use App\Livewire\Flights\FlightPage;
use App\Livewire\Quality\FlagList;
use App\Livewire\Quality\FlagPage;
use App\Livewire\Reference\AircraftTypes;
use App\Livewire\Reference\AirportCodes;
use App\Livewire\Reference\Stands;
use App\Livewire\Users\UserList;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

/*
 * Admin screens. Every route carries an ability middleware (can:…), except
 * sign-in, two-factor and sign-out (tests/Feature/Admin/RouteAbilitiesTest).
 */

// Sign-in: email + password, then TOTP (Fortify controllers; its own routes are not registered).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('signin.throttle')->name('login.store');
    Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
        ->middleware('challenge.unlocked')->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware(['signin.throttle', 'challenge.unlocked'])->name('two-factor.login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

// TOTP enrolment: mandatory before any page.
Route::middleware(['auth', 'account:enrol'])->group(function () {
    Route::get('/two-factor/enrol', [TwoFactorEnrolmentController::class, 'show'])->name('two-factor.enrol');
    Route::post('/two-factor/enrol', [TwoFactorEnrolmentController::class, 'confirm'])->name('two-factor.confirm');
});

// Password change: forced while must_change_password is set.
Route::middleware(['auth', 'account:password', 'can:view-hub'])->group(function () {
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.change');
    Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'account'])->group(function () {
    Route::middleware('can:view-hub')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::livewire('/flights', DayView::class)->name('flights.day');
        Route::livewire('/flights/{aodbId}', FlightPage::class)->where('aodbId', '[A-Za-z0-9._:-]{1,64}')->name('flights.show');
        // Seen by view-hub; resolving needs manage-quality (checked in each action).
        Route::livewire('/quality', FlagList::class)->name('quality.flags');
        Route::livewire('/quality/{flag}', FlagPage::class)->whereNumber('flag')->name('quality.flag');
    });

    Route::middleware('can:manage-reference')->group(function () {
        Route::livewire('/reference/airport-codes', AirportCodes::class)->name('reference.airport-codes');
        Route::livewire('/reference/aircraft-types', AircraftTypes::class)->name('reference.aircraft-types');
        Route::livewire('/reference/stands', Stands::class)->name('reference.stands');
    });

    Route::middleware('can:manage-consumers')->group(function () {
        Route::livewire('/consumers', ConsumerList::class)->name('consumers.list');
        Route::livewire('/consumers/{code}', ConsumerPage::class)->where('code', '[a-z0-9-]{1,32}')->name('consumers.show');
        Route::livewire('/consumers/{code}/usage', ConsumerUsage::class)->where('code', '[a-z0-9-]{1,32}')->name('consumers.usage');
    });

    Route::livewire('/users', UserList::class)->middleware('can:manage-users')->name('users.list');
    Route::livewire('/audit', AuditLog::class)->middleware('can:view-audit')->name('audit.log');
});

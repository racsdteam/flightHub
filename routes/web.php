<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\TwoFactorEnrolmentController;
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
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
        ->middleware('challenge.unlocked')->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware('challenge.unlocked')->name('two-factor.login.store');
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

Route::middleware(['auth', 'account', 'can:view-hub'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
});

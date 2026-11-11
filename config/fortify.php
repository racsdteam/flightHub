<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify (admin sign-in, D-040, D-043)
    |--------------------------------------------------------------------------
    |
    | Only sign-in with a confirmed TOTP. No registration, no e-mail password
    | reset, no e-mail verification, no passkeys. The routes are registered in
    | routes/web.php (Fortify::ignoreRoutes()); the account lock replaces
    | Fortify's login throttle (App\Admin\Accounts).
    |
    */

    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'views' => true,
    'home' => '/',
    'prefix' => '',
    'domain' => null,
    'limiters' => [
        'login' => null,
        'passkeys' => null,
    ],
    'redirects' => [
        'login' => '/',
        'logout' => '/login',
    ],

    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => false,
        ]),
    ],

];

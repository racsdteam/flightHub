<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Gate abilities per role (D-040), deny-by-default: an ability not listed
 * here is refused to everyone, and an inactive user holds none.
 */
final class Abilities
{
    public const ROLES = [
        'view-hub' => ['admin', 'steward'],
        'manage-quality' => ['admin', 'steward'],
        'manage-reference' => ['admin', 'steward'],
        'manage-consumers' => ['admin'],
        'manage-users' => ['admin'],
        'view-audit' => ['admin'],
    ];

    public static function register(): void
    {
        foreach (self::ROLES as $ability => $roles) {
            Gate::define($ability, fn (User $user) => $user->active && in_array($user->role, $roles, true));
        }
    }
}

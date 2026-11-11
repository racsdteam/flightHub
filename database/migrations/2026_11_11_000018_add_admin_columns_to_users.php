<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin accounts (D-040, D-043): role, activation, forced password change,
     * the account lock, and Fortify's two-factor columns (secret and recovery
     * codes stored encrypted by Fortify). Users are never deleted: deactivate.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | steward
            $table->string('role', 16)->default('steward')->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->boolean('must_change_password')->default(true)->after('active');
            $table->unsignedInteger('failed_logins')->default(0)->after('must_change_password');
            $table->dateTime('locked_until')->nullable()->after('failed_logins');
            $table->dateTime('last_login_at')->nullable()->after('locked_until');
            $table->text('two_factor_secret')->nullable()->after('last_login_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role', 'active', 'must_change_password', 'failed_logins', 'locked_until', 'last_login_at',
                'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            ]);
        });
    }
};

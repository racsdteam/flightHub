<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * admin_audit: every admin action, append-only, kept forever. Never a
     * password, TOTP secret or code, recovery code, API key or session id.
     */
    public function up(): void
    {
        Schema::create('admin_audit', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('user_id')->nullable();
            // The user's email, "cli" or "hub".
            $table->string('actor', 100);
            $table->string('action', 48);
            $table->string('target_type', 48)->nullable();
            $table->string('target_id', 64)->nullable();
            // Before / after values; never secrets.
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index('at');
            $table->index(['action', 'at']);
        });

        DB::unprepared("CREATE TRIGGER admin_audit_before_update BEFORE UPDATE ON admin_audit FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admin_audit is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER admin_audit_before_delete BEFORE DELETE ON admin_audit FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admin_audit is append-only: DELETE refused'");
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit');
    }
};

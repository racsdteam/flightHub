<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * api_keys: one or more keys per consumer. Key format
     * fdh_<8-char prefix>_<≥ 32 random base62>; only its SHA-256 is stored. The
     * full key is shown once, at issue.
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained('consumers');
            // "fdh_" + the 8-char prefix: shown to people, used for lookup.
            $table->string('prefix', 16)->unique();
            // SHA-256 (hex) of the whole key.
            $table->char('secret_hash', 64);
            $table->string('label', 100);
            $table->string('created_by', 100);
            $table->dateTime('expires_at')->nullable();
            // Updated at most once a minute.
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_by', 100)->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};

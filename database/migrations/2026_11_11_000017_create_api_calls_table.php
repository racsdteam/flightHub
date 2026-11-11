<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * api_calls: every distribution API call, refused ones included. Append-only;
     * kept 1 year (D-036). Never the key, never the Authorization header.
     */
    public function up(): void
    {
        Schema::create('api_calls', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at', 6);
            // Null when the call was refused before a key was accepted.
            $table->unsignedBigInteger('consumer_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('method', 8);
            // Path only, never the query string.
            $table->string('path', 255);
            // Allow-listed query parameters only, values cut to 100 chars.
            $table->json('params')->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('rows')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45);
            $table->string('error_code', 32)->nullable();

            $table->index('at');
            $table->index(['consumer_id', 'at']);
        });

        DB::unprepared("CREATE TRIGGER api_calls_before_update BEFORE UPDATE ON api_calls FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'api_calls is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER api_calls_before_delete BEFORE DELETE ON api_calls FOR EACH ROW
            BEGIN
                IF NOT (OLD.at < UTC_TIMESTAMP() - INTERVAL 365 DAY) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'api_calls: DELETE refused for rows younger than 365 days';
                END IF;
            END");
    }

    public function down(): void
    {
        Schema::dropIfExists('api_calls');
    }
};

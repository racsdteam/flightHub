<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * raw_responses: stored only when the content differs from the last stored
     * one (D-008). History table: no UPDATE; DELETE only after 90 days.
     */
    public function up(): void
    {
        Schema::create('raw_responses', function (Blueprint $table) {
            $table->id();
            $table->dateTime('received_at', 6);
            // sha256 of the canonical payload (JSON re-encoded with sorted keys).
            $table->char('sha256', 64);
            // gzip of the exact bytes received; widened to LONGBLOB below.
            $table->binary('body');
            $table->unsignedInteger('bytes_raw');
            $table->unsignedInteger('bytes_compressed');
            $table->unsignedSmallInteger('http_status');
            // Path only, never the query string.
            $table->string('endpoint', 255);
            $table->dateTime('request_window_from')->nullable();
            $table->dateTime('request_window_to')->nullable();

            $table->index('received_at');
            // Not unique: A -> B -> A stores A twice.
            $table->index('sha256');
        });

        DB::statement('ALTER TABLE raw_responses MODIFY body LONGBLOB NOT NULL');

        DB::unprepared("CREATE TRIGGER raw_responses_before_update BEFORE UPDATE ON raw_responses FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_responses is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER raw_responses_before_delete BEFORE DELETE ON raw_responses FOR EACH ROW
            BEGIN
                IF NOT (OLD.received_at < UTC_TIMESTAMP() - INTERVAL 90 DAY) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_responses: DELETE refused for rows younger than 90 days';
                END IF;
            END");
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_responses');
    }
};

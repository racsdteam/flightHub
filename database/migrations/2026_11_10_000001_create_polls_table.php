<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * polls: one row per poll attempt, inserted once when the attempt ends.
     * History table (append-only, kept forever).
     */
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6);
            $table->unsignedInteger('duration_ms');
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->char('payload_sha256', 64)->nullable();
            $table->boolean('content_changed');
            // Logical reference only: raw rows are pruned after 90 days.
            $table->unsignedBigInteger('raw_response_id')->nullable();
            $table->unsignedInteger('flights_in_payload')->nullable();
            $table->unsignedInteger('flights_changed');
            $table->unsignedInteger('events_written');

            $table->index('started_at');
            $table->index(['outcome', 'started_at']);
        });

        DB::unprepared("CREATE TRIGGER polls_before_update BEFORE UPDATE ON polls FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'polls is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER polls_before_delete BEFORE DELETE ON polls FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'polls is append-only: DELETE refused'");
    }

    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};

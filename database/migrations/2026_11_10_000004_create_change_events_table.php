<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * change_events: one row per changed column, plus lifecycle events.
     * History table (append-only, kept forever). id is the "changes since" cursor.
     */
    public function up(): void
    {
        Schema::create('change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            // The version this change produced.
            $table->foreignId('version_id')->constrained('flight_versions');
            $table->string('kind', 32);
            $table->string('field', 64)->nullable();
            // Times as ISO-8601 UTC strings, e.g. "2026-10-29T05:04:00Z".
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('source', 16);
            $table->string('source_ref', 64)->nullable();
            $table->foreignId('poll_id')->nullable()->constrained('polls');
            $table->dateTime('observed_at', 6);

            $table->index(['flight_id', 'id']);
            $table->index('observed_at');
            $table->index('version_id');
        });

        DB::unprepared("CREATE TRIGGER change_events_before_update BEFORE UPDATE ON change_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'change_events is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER change_events_before_delete BEFORE DELETE ON change_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'change_events is append-only: DELETE refused'");
    }

    public function down(): void
    {
        Schema::dropIfExists('change_events');
    }
};

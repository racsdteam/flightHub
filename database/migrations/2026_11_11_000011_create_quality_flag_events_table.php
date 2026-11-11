<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * quality_flag_events: append-only history of every flag (detected,
     * cleared, reopened, resolved). actor null = the hub.
     */
    public function up(): void
    {
        Schema::create('quality_flag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flag_id')->constrained('quality_flags');
            $table->string('kind', 16);
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32)->nullable();
            $table->string('actor', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('at', 6);
        });

        DB::unprepared("CREATE TRIGGER quality_flag_events_before_update BEFORE UPDATE ON quality_flag_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quality_flag_events is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER quality_flag_events_before_delete BEFORE DELETE ON quality_flag_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quality_flag_events is append-only: DELETE refused'");
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_flag_events');
    }
};

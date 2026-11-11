<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * quality_flags: current state of each data-quality condition, never
     * deleted. state (open / cleared) is set by the hub; resolution by a person.
     */
    public function up(): void
    {
        Schema::create('quality_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->string('rule', 48);
            // AODB path, for per-field rules.
            $table->string('field', 64)->nullable();
            // The other flight, for duplicates.
            $table->foreignId('related_flight_id')->nullable()->constrained('flights');
            // rule|field|related aodb_id
            $table->string('flag_key', 160);
            $table->string('state', 16);
            // The latest values that triggered the flag.
            $table->json('details')->nullable();
            $table->dateTime('first_detected_at', 6);
            $table->dateTime('last_detected_at', 6);
            $table->dateTime('cleared_at', 6)->nullable();
            // null | fixed | ignored | reported | same_as
            $table->string('resolution', 16)->nullable();
            $table->string('resolved_by', 100)->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->datetimes();

            $table->unique(['flight_id', 'flag_key']);
            $table->index(['state', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_flags');
    }
};

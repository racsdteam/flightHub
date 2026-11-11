<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reviewed aircraft-type reference (D-033): fills a missing ICAO type, type
     * name and wake category. Capacity is never filled. No seed rows: rows are
     * added only after Dany reviews them, each batch in its own migration.
     */
    public function up(): void
    {
        Schema::create('aircraft_types', function (Blueprint $table) {
            $table->id();
            // '*' = any airline; an airline's own row wins over '*'.
            $table->string('airline_iata', 3);
            $table->string('ac_type_iata', 4);
            $table->string('ac_type_icao', 4)->nullable();
            $table->string('type_name', 64)->nullable();
            $table->string('wtc', 1)->nullable();
            // Stored for the distribution API; no turnaround quality rule.
            $table->integer('min_turnaround_minutes')->nullable();
            $table->string('reviewed_by', 100);
            $table->date('reviewed_on');
            $table->boolean('active')->default(true);
            $table->datetimes();

            $table->unique(['airline_iata', 'ac_type_iata']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_types');
    }
};

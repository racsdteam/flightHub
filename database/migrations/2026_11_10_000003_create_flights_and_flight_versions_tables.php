<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * flights: current state, one row per AODB flight, never deleted.
     * flight_versions: append-only full copy of a flight's data on every change.
     * Both share the data, twin and derived columns defined in flightColumns().
     */
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('aodb_id', 64)->unique();

            $this->flightColumns($table);

            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->boolean('in_feed')->default(true);
            $table->dateTime('removed_from_feed_at', 6)->nullable();
            $table->unsignedInteger('current_version_no')->default(0);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->datetimes();

            $table->index('reference_time');
            $table->index(['reg', 'reference_time']);
            $table->index(['full_flight_number', 'reference_time']);
        });

        Schema::create('flight_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->unsignedInteger('version_no');
            // When the hub observed this state.
            $table->dateTime('valid_from', 6);
            $table->foreignId('poll_id')->nullable()->constrained('polls');
            $table->string('cause', 16);

            $this->flightColumns($table);

            $table->boolean('in_feed');

            $table->unique(['flight_id', 'version_no']);
            // These two serve as_of (D-022).
            $table->index(['flight_id', 'valid_from', 'id']);
            $table->index(['reference_time', 'valid_from']);
        });

        DB::unprepared("CREATE TRIGGER flight_versions_before_update BEFORE UPDATE ON flight_versions FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'flight_versions is append-only: UPDATE refused'");
        DB::unprepared("CREATE TRIGGER flight_versions_before_delete BEFORE DELETE ON flight_versions FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'flight_versions is append-only: DELETE refused'");
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_versions');
        Schema::dropIfExists('flights');
    }

    /**
     * AODB data columns (names = AODB fields in snake_case), the <field>_aodb
     * twins of the 8 fields reference data may correct or fill (D-006), and the
     * derived reference_time. Generous and nullable: the schema never rejects
     * AODB data; validation belongs to the quality layer.
     */
    private function flightColumns(Blueprint $table): void
    {
        $table->string('flow', 16)->nullable();
        $table->string('flight_number', 32)->nullable();
        $table->string('full_flight_number', 32)->nullable();
        $table->string('airline_iata', 16)->nullable();
        $table->string('airline_icao', 16)->nullable();
        $table->string('adep_iata', 16)->nullable();
        $table->string('adep_iata_aodb', 16)->nullable();
        $table->string('adep_icao', 16)->nullable();
        $table->string('adep_icao_aodb', 16)->nullable();
        $table->string('ades_iata', 16)->nullable();
        $table->string('ades_iata_aodb', 16)->nullable();
        $table->string('ades_icao', 16)->nullable();
        $table->string('ades_icao_aodb', 16)->nullable();
        $table->string('reg', 32)->nullable();
        $table->string('ac_type_iata', 16)->nullable();
        $table->string('ac_type_icao', 16)->nullable();
        $table->string('ac_type_icao_aodb', 16)->nullable();
        $table->string('ac_type_name', 255)->nullable();
        $table->string('ac_type_name_aodb', 255)->nullable();
        $table->string('wtc', 16)->nullable();
        $table->string('wtc_aodb', 16)->nullable();
        $table->decimal('mtow', 12, 3)->nullable();
        $table->string('status', 64)->nullable();
        $table->dateTime('sobt')->nullable();
        $table->dateTime('sibt')->nullable();
        $table->dateTime('etot')->nullable();
        $table->dateTime('atot')->nullable();
        $table->dateTime('aobt')->nullable();
        $table->dateTime('eldt')->nullable();
        $table->dateTime('aldt')->nullable();
        $table->dateTime('eibt')->nullable();
        $table->dateTime('aibt')->nullable();
        $table->string('pkg', 32)->nullable();
        $table->integer('pax_capa')->nullable();
        $table->integer('pax_capa_aodb')->nullable();
        $table->integer('pax_nb')->nullable();
        $table->json('dl_codes')->nullable();

        $table->dateTime('reference_time')->nullable();
        $table->string('reference_time_source', 16)->nullable();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rotations: hub-owned served values. Arrivals point to their next
     * departure, departures to their previous arrival. rotation_source: aodb
     * (linkedId) or registration (derived). Changes are events
     * "hub.rotationNext" / "hub.rotationPrev".
     */
    public function up(): void
    {
        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('rotation_next_flight_id')->nullable()->after('same_as_flight_id')->constrained('flights');
                $table->foreignId('rotation_prev_flight_id')->nullable()->after('rotation_next_flight_id')->constrained('flights');
                $table->string('rotation_source', 16)->nullable()->after('rotation_prev_flight_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('rotation_next_flight_id');
                $table->dropConstrainedForeignId('rotation_prev_flight_id');
                $table->dropColumn('rotation_source');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hub-owned served value: a person resolved this flight as "same as"
     * another (D-005). Its changes are events with field "hub.sameAs".
     */
    public function up(): void
    {
        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('same_as_flight_id')->nullable()->after('reference_time_source')->constrained('flights');
            });
        }
    }

    public function down(): void
    {
        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('same_as_flight_id');
            });
        }
    }
};

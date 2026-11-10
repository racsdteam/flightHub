<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The whole flight record as received (D-024) and the A-CDM columns, on the
     * current state and on every version. Adding columns does not update rows,
     * so the append-only triggers are not involved.
     */
    public function up(): void
    {
        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // The flight object as received, keys sorted recursively, nothing added or removed.
                $table->json('record');
                $table->dateTime('eobt')->nullable()->after('sobt');
                $table->dateTime('tobt')->nullable()->after('eobt');
                $table->string('linked_id', 64)->nullable()->after('flow');
                $table->string('milestone', 32)->nullable()->after('status');
            });
        }

        Schema::table('flights', function (Blueprint $table) {
            $table->index('linked_id');
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropIndex(['linked_id']);
        });

        foreach (['flights', 'flight_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['record', 'eobt', 'tobt', 'linked_id', 'milestone']);
            });
        }
    }
};

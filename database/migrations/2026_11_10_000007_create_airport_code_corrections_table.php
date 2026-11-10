<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reviewed mapping table for adepIata / adesIata (D-006, D-028). Only rows
     * here may change a served value; everything else is only flagged.
     */
    public function up(): void
    {
        Schema::create('airport_code_corrections', function (Blueprint $table) {
            $table->id();
            // Matched against adepIata and adesIata as received.
            $table->string('aodb_value', 16)->unique();
            $table->char('corrected_iata', 3);
            $table->char('corrected_icao', 4)->nullable();
            $table->string('reason', 255);
            $table->string('reviewed_by', 100);
            $table->date('reviewed_on');
            $table->boolean('active')->default(true);
            $table->datetimes();
        });

        $now = gmdate('Y-m-d H:i:s');
        DB::table('airport_code_corrections')->insert([
            'aodb_value' => 'NB0',
            'corrected_iata' => 'NBO',
            'corrected_icao' => 'HKJK',
            'reason' => 'Nairobi written with a zero (KQ470, KQ478, 2026-10-29 sample)',
            'reviewed_by' => 'Dany Kabalisa',
            'reviewed_on' => '2026-11-10',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('airport_code_corrections');
    }
};

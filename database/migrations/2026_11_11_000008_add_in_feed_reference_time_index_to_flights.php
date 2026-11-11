<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Serves the poll's removal pre-filter: in-feed flights by reference_time.
     */
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->index(['in_feed', 'reference_time']);
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropIndex(['in_feed', 'reference_time']);
        });
    }
};

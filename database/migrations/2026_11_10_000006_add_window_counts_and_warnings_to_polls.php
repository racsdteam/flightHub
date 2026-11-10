<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The requested window, lifecycle counts and warnings of each poll.
     */
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dateTime('request_window_from')->after('finished_at');
            $table->dateTime('request_window_to')->after('request_window_from');
            $table->unsignedInteger('flights_new')->default(0)->after('flights_in_payload');
            $table->unsignedInteger('flights_removed')->default(0)->after('flights_changed');
            $table->unsignedInteger('flights_returned')->default(0)->after('flights_removed');
            // List of {code, aodb_id?, detail}; never secrets.
            $table->json('warnings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropColumn([
                'request_window_from', 'request_window_to',
                'flights_new', 'flights_removed', 'flights_returned', 'warnings',
            ]);
        });
    }
};

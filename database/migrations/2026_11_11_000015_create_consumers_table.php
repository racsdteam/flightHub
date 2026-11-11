<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * consumers: the RAC systems that read the distribution API. Never deleted
     * (disable instead). field_groups: the named groups they receive (D-039).
     */
    public function up(): void
    {
        Schema::create('consumers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->json('field_groups');
            $table->integer('rate_limit_per_minute')->default(120);
            $table->string('notes', 500)->nullable();
            $table->string('created_by', 100);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumers');
    }
};

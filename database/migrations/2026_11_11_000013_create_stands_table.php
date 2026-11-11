<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reviewed list of KIA stands (pkg). The stand_unknown rule is active only
     * when at least one row is active. No seed rows: rows are added only after
     * Dany reviews them, each batch in its own migration.
     */
    public function up(): void
    {
        Schema::create('stands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->boolean('active')->default(true);
            $table->string('remarks', 255)->nullable();
            $table->string('reviewed_by', 100);
            $table->date('reviewed_on');
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stands');
    }
};

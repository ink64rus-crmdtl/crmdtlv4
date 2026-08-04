<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index()->comment('System key, e.g. client_source, vehicle_body');
            $table->string('value')->comment('The actual value, e.g. Авито, Седан');
            $table->string('color')->nullable()->comment('Optional color for badges');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Значение должно быть уникальным в рамках одного типа справочника
            $table->unique(['type', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
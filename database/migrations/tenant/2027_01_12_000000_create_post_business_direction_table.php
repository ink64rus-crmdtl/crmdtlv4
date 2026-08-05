<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_business_direction', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();

            // Имя PK задано вручную — автоматическое имя Laravel для этой пары колонок
            // превышает лимит идентификатора MySQL в 64 символа (см. тот же приём в
            // миграции branch_business_direction).
            $table->primary(['post_id', 'business_direction_id'], 'post_bus_dir_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_business_direction');
    }
};

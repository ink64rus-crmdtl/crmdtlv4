<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_business_direction', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();
            
            // Чтобы имя ключа не было слишком длинным для MySQL, задаем его явно
            $table->primary(['branch_id', 'business_direction_id'], 'branch_bus_dir_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_business_direction');
    }
};
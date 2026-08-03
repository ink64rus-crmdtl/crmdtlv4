<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained('vehicle_makes')->cascadeOnDelete();
            $table->string('name');
            $table->string('body_type')->nullable()->comment('Тип кузова (Седан, Кроссовер и т.д.)');
            $table->string('category')->nullable()->comment('Категория (Легковой, Коммерческий и т.д.)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
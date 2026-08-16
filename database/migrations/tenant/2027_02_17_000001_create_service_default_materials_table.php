<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правило автодобавления материала (CLAUDE.md, «Материалы на услугу»):
 * при добавлении услуги в заказ система предлагает добавить эти материалы
 * в заданном количестве (см. Setting service_material_auto_add_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_default_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->timestamps();

            $table->unique(['service_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_default_materials');
    }
};

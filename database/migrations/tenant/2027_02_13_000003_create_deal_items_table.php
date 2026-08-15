<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предварительная смета сделки — смысловая копия appointment_items:
 * только для коммерческого предложения клиенту, БЕЗ резервирования
 * остатков и БЕЗ движения по счетам. Склад и финансы трогаются
 * исключительно после конвертации в WorkOrder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();

            $table->nullableMorphs('itemable');

            $table->string('name')->comment('Snapshot of the item name');
            $table->decimal('quantity', 10, 3)->default(1.000);
            $table->integer('price')->default(0)->comment('Ориентировочная цена за единицу, в минимальных единицах валюты');
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_items');
    }
};

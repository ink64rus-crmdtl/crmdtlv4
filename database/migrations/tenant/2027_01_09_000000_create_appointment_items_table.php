<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();

            // Полиморфная связь (Service или Product) — только для ориентировочной сметы,
            // без резервирования остатков (склад трогается лишь после конвертации в WorkOrder).
            $table->nullableMorphs('itemable');

            $table->string('name')->comment('Snapshot of the item name');
            $table->decimal('quantity', 10, 3)->default(1.000);
            $table->integer('price')->default(0)->comment('Ориентировочная цена за единицу, в минимальных единицах валюты');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_items');
    }
};

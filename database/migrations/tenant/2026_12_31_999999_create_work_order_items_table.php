<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            
            // Полиморфная связь (может ссылаться на Service или Product/Material)
            $table->nullableMorphs('itemable');
            
            $table->string('name')->comment('Snapshot of the item name');
            $table->decimal('quantity', 10, 3)->default(1.000);
            
            // Денежные поля (Фаза 5)
            $table->integer('price')->default(0)->comment('Price per unit in minimal currency units');
            $table->integer('total')->default(0)->comment('quantity * price - discount');
            $table->unsignedBigInteger('currency_id')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
    }
};
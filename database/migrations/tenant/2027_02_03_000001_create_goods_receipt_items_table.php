<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Партия создаётся StockService::receipt() ПОСЛЕ вставки позиции
            // (нужен product_id/quantity/cost_price заранее) — nullable, как
            // и stock_movements.product_batch_id у сиблинга.
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->integer('cost_price')->comment('Себестоимость за единицу в копейках');
            $table->string('batch_number')->nullable()->comment('Номер партии/серии С НАКЛАДНОЙ поставщика — пробрасывается в product_batches.batch_number');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};

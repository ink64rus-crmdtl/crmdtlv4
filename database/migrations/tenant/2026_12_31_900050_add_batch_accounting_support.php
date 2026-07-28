<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Добавляем тип учета в справочник товаров
        Schema::table('products', function (Blueprint $table) {
            $table->string('accounting_type')->default('average')->comment('average (средневзвешенный) or batch (партионный / FIFO)')->after('unit');
        });

        // 2. Создаем таблицу партий товаров
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            
            $table->string('batch_number')->nullable()->comment('Номер партии / накладной поступления');
            $table->decimal('initial_quantity', 10, 3);
            $table->decimal('current_quantity', 10, 3);
            
            // Себестоимость конкретной партии (integer копейки)
            $table->integer('cost_price')->default(0);
            $table->unsignedBigInteger('currency_id')->nullable();
            
            $table->date('manufactured_at')->nullable();
            $table->date('expired_at')->nullable()->comment('Срок годности (для химии)');
            
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Привязываем партии к движениям по складу
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained('product_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['product_batch_id']);
            $table->dropColumn('product_batch_id');
        });

        Schema::dropIfExists('product_batches');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('accounting_type');
        });
    }
};
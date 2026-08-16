<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Дефолт для WorkOrderItem.allow_negative_stock при добавлении этого товара
            // в заказ — и как материала на услугу, и как обычной позиции на продажу
            // (CLAUDE.md, «Отдельные тумблеры разрешения списания в минус для материалов
            // и для товаров на продажу»). Консервативный дефолт false — как и у самого
            // WorkOrderItem.allow_negative_stock, недостача по умолчанию блокируется.
            $table->boolean('allow_negative_stock_by_default')->default(false)->after('affects_payroll_by_default');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allow_negative_stock_by_default');
        });
    }
};

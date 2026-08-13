<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оплата поставщику (см. CLAUDE.md, склад) — раньше GoodsReceipt деньги
 * не отслеживал вообще ("оплата — отдельная ручная операция"). Теперь
 * привязывается к Transaction тем же полиморфным payable_type/payable_id,
 * что и WorkOrder/Payroll (см. GoodsReceipt::transactions(), 'payable' —
 * общая колонка, отдельная FK-миграция не нужна). payment_status — тот же
 * паттерн, что и у WorkOrder: unpaid/partial/paid, пересчитывается
 * GoodsReceipt::syncPaymentStatus() при каждой оплате/откате транзакции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')->after('status')
                ->comment('unpaid/partial/paid — считается от суммы expense-транзакций payable_type=GoodsReceipt');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
    }
};

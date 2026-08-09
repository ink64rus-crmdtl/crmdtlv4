<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // true — скидку по заказу задал вручную сотрудник (updateDiscount()),
            // recalculateTotals() тогда НЕ пересчитывает её автоматически по
            // скидке грейда клиента (ручной выбор в приоритете, тот же принцип,
            // что и у Client.client_group_locked).
            $table->boolean('discount_is_manual')->default(false)->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('discount_is_manual');
        });
    }
};

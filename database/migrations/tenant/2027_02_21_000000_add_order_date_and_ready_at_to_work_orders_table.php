<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Бизнес-дата/время создания — редактируемая, отдельно от технического
            // created_at (тот же принцип, что и Transaction.transaction_date, только
            // с точностью до минуты).
            $table->dateTime('order_date')->nullable()->after('mileage');
            // Дедлайн "выполнить до" — опционален, бейдж на карточке показывается,
            // только если задан.
            $table->dateTime('ready_at')->nullable()->after('order_date');
        });

        // Бэкфилл существующих заказов: до этой миграции бизнес-даты не было —
        // ближайшее разумное значение для старых записей = момент их создания.
        DB::table('work_orders')->whereNull('order_date')->update(['order_date' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['order_date', 'ready_at']);
        });
    }
};

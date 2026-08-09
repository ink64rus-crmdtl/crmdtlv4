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
        Schema::table('client_groups', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('cashback_percent');
            // NULL = условие не задано (не участвует в проверке). Если задано и
            // оборот, и кол-во заказов — оба должны выполняться одновременно (AND),
            // а не любое из двух — предсказуемее для настройки одного порога на группу.
            $table->unsignedBigInteger('min_turnover_amount')->nullable()->after('discount_percent')->comment('В копейках, сумма завершённых заказов за период');
            $table->unsignedInteger('min_orders_count')->nullable()->after('min_turnover_amount')->comment('Кол-во завершённых заказов за период');
            $table->unsignedInteger('auto_assign_period_days')->nullable()->default(90)->after('min_orders_count')->comment('Окно в днях для проверки оборота/кол-ва заказов');
            // Порядок проверки при автоподборе — от меньшего к большему, первая
            // подошедшая группа побеждает (меньше = выше приоритет = "старше" грейд).
            $table->integer('sort_order')->default(0)->after('auto_assign_period_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'min_turnover_amount', 'min_orders_count', 'auto_assign_period_days', 'sort_order']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * НДС на заказе — ставка/принцип СНЕПШОТЯТСЯ в момент пересчёта
 * (WorkOrderController::recalculateTotals()), а не читаются заново из
 * юрлица при каждом открытии: та же логика, что у Document — "зафиксированный
 * во времени артефакт" (см. Document::isStale()). Если юрлицо потом сменит
 * ставку НДС, уже посчитанные заказы не должны молча пересчитаться.
 *
 * vat_amount — integer, копейки, как и остальные денежные поля work_orders.
 * total_amount/discount_amount не меняют смысла из-за этой миграции —
 * final_amount получает надбавку НДС ТОЛЬКО в режиме exclusive (см.
 * recalculateTotals()), в остальных случаях (без НДС, либо inclusive)
 * формула final_amount побайтово совпадает с тем, что была до НДС.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('final_amount');
            $table->string('vat_calculation_method')->nullable()->after('vat_rate');
            $table->integer('vat_amount')->default(0)->after('vat_calculation_method')->comment('Сумма НДС в копейках — либо выделена из final_amount (inclusive), либо добавлена к нему (exclusive)');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'vat_calculation_method', 'vat_amount']);
        });
    }
};

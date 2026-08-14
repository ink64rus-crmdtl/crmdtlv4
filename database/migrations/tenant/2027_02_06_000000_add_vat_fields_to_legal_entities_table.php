<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * НДС — настройка per-юрлицо (не глобально на тенанта): у одного тенанта
 * могут быть юрлица с разными налоговыми режимами (ИП на патенте без НДС
 * и ООО на ОСН с НДС в рамках одной компании). Отдельные типизированные
 * колонки, а не ключи внутри свободного JSON requisites — это поле
 * читается на каждом пересчёте суммы заказа/накладной (WorkOrderController::
 * recalculateTotals(), StockService), должно быть быстрым и типобезопасным;
 * requisites целиком и без разбора уходит в плейсхолдеры документов
 * {{legal_entity.*}}, что для расчёта денег неуместно.
 *
 * vat_calculation_method: 'inclusive' — цена уже включает НДС (выделяем
 * налог из уже имеющейся суммы, итог не меняется), 'exclusive' — НДС
 * начисляется сверху (итог увеличивается на сумму налога). См. CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', function (Blueprint $table) {
            $table->boolean('vat_payer')->default(false)->after('tax_id');
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('vat_payer')->comment('Ставка НДС в процентах, из списка допустимых для страны тенанта — см. CountryConfigService');
            $table->string('vat_calculation_method')->nullable()->after('vat_rate')->comment('inclusive — цена уже включает НДС, exclusive — НДС начисляется сверху');
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table) {
            $table->dropColumn(['vat_payer', 'vat_rate', 'vat_calculation_method']);
        });
    }
};

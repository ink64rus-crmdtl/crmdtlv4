<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * НДС на приходной накладной — та же архитектура, что и у work_orders
 * (2027_02_07): рассчитывается/снепшотится StockService::createReceiptItem()/
 * updateReceiptItem() при каждом изменении позиций, от юрлица накладной
 * (та же резолюция, что и в печатных документах — resolveLegalEntity()).
 *
 * vat_rate/vat_amount — НА КАЖДОЙ ПОЗИЦИИ (не на шапке, в отличие от
 * work_orders): в накладной от одного поставщика товары технически могут
 * идти по разным ставкам НДС (например часть — 20%, часть — льготная 10%),
 * в заказ-наряде такое почти не встречается, поэтому там ставка на шапке
 * достаточна. vat_calculation_method — на шапке накладной, применяется
 * ко всем её позициям сразу: разный принцип расчёта (включая/сверху) для
 * позиций одной накладной от одного поставщика физически не бывает —
 * это способ, которым ИМЕННО ЭТОТ поставщик указывает цены в своих
 * документах, а не свойство конкретного товара.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('vat_calculation_method')->nullable()->after('status')->comment('inclusive — цена уже включает НДС, exclusive — НДС начисляется сверху');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('cost_price');
            $table->integer('vat_amount')->default(0)->after('vat_rate')->comment('Сумма НДС по позиции в копейках');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'vat_amount']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('vat_calculation_method');
        });
    }
};

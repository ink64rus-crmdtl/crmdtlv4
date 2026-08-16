<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материал, потраченный на услугу (Вариант C — CLAUDE.md, «Материалы на
 * услугу»): поля содержательны только когда linked_item_id задан
 * (проверяется в контроллере, не на уровне БД — тот же принцип, что уже
 * применяется к discount_amount/discount_is_manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            // Обратная совместимость: существующие строки (услуги, обычные
            // проданные товары) остаются billable, как и были всегда.
            // Материал контроллер создаёт с явным false.
            $table->boolean('is_billable')->default(true)->after('linked_item_id');
            $table->integer('cost_price')->nullable()->after('is_billable');
            $table->boolean('affects_payroll')->default(true)->after('cost_price');
            $table->boolean('payroll_uses_cost_only')->default(false)->after('affects_payroll');
            $table->boolean('stock_deduction_disabled')->default(false)->after('payroll_uses_cost_only');
            $table->boolean('allow_negative_stock')->default(false)->after('stock_deduction_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'is_billable',
                'cost_price',
                'affects_payroll',
                'payroll_uses_cost_only',
                'stock_deduction_disabled',
                'allow_negative_stock',
            ]);
        });
    }
};

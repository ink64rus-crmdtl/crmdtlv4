<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * У заказа мог быть только один администратор по умолчанию
 * (work_orders.default_admin_employee_id) — у части клиентов на заказе
 * реально работают 2+ администратора (например, менеджер принял заказ,
 * второй вёл сделку) и делят начисление между собой. Было 1:0..1, стало
 * многие-ко-многим, по образцу work_order_item_employees (та же тройка
 * share_percent/manual_amount_override/manual_percent_override).
 *
 * up() переносит существующее значение в пивот и удаляет исходную колонку —
 * сознательное отступление от "никогда не удаляй колонки" (CLAUDE.md, п.8):
 * прямо запрошенная архитектурная замена связи, данные переносятся, а не
 * выбрасываются (см. прецедент branch_legal_entity, миграция 2027_01_28).
 * admin_assignment_mode (auto/manual) остаётся как есть — по-прежнему решает,
 * перезаписывать ли список автоназначением по создателю заказа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2)->nullable()->comment('Доля начисления между админами заказа; null = равная доля');
            $table->integer('manual_amount_override')->nullable()->comment('Ручная сумма (копейки), взаимоисключающе с manual_percent_override');
            $table->decimal('manual_percent_override', 5, 2)->nullable()->comment('Ручной % от базы, взаимоисключающе с manual_amount_override');
            $table->timestamps();

            $table->unique(['work_order_id', 'employee_id']);
        });

        $now = now();
        DB::table('work_orders')
            ->whereNotNull('default_admin_employee_id')
            ->get(['id', 'default_admin_employee_id'])
            ->each(function ($order) use ($now) {
                DB::table('work_order_admins')->insertOrIgnore([
                    'work_order_id' => $order->id,
                    'employee_id' => $order->default_admin_employee_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['default_admin_employee_id']);
            $table->dropColumn('default_admin_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('default_admin_employee_id')->nullable()->after('created_by')->constrained('employees')->nullOnDelete();
        });

        // М:М -> 1:0..1 необратимо без потери информации при 2+ админах —
        // восстанавливаем первого попавшегося, остальные при откате теряются.
        DB::table('work_order_admins')
            ->select('work_order_id', DB::raw('MIN(employee_id) as employee_id'))
            ->groupBy('work_order_id')
            ->get()
            ->each(fn ($row) => DB::table('work_orders')->where('id', $row->work_order_id)->update(['default_admin_employee_id' => $row->employee_id]));

        Schema::dropIfExists('work_order_admins');
    }
};

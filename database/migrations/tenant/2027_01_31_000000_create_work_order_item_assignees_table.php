<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Исполнителем позиции-услуги может быть не только штатный сотрудник, но и
 * Подрядчик — а подрядчик у нас не Employee, а Client с ролью «Подрядчик»
 * (client_roles, миграция 2027_01_30). Пивот work_order_item_employees жёстко
 * знал только про employees.employee_id, поэтому заменяется полиморфной
 * парой assignee_type/assignee_id — тот же приём, что уже применён в проекте
 * для work_order_items.itemable (Service|Product).
 *
 * Бизнес-правило «на одной позиции нельзя смешивать штатных исполнителей и
 * подрядчиков» на уровне схемы НЕ выражается (обе стороны — строки одной
 * таблицы) и проверяется в WorkOrderController — здесь это отмечено, чтобы
 * при чтении схемы не искали несуществующий констрейнт.
 *
 * work_order_item_admins сознательно НЕ трогаем: администратор позиции — это
 * всегда штатный сотрудник (подрядчик не может быть администратором услуги),
 * поэтому полиморфизм там был бы лишней сложностью без сценария.
 *
 * FK на assignee_id невозможен (полиморф) — целостность держится на том, что
 * и Employee, и Client используют SoftDeletes: реальных удалений строк, на
 * которые ссылается пивот, в штатном сценарии не происходит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_item_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->string('assignee_type')->comment('App\Models\Employee — штатный исполнитель, App\Models\Client — подрядчик');
            $table->unsignedBigInteger('assignee_id');
            $table->decimal('share_percent', 5, 2)->nullable()->comment('Доля объёма работ в бригаде; null = равная доля между всеми штатными исполнителями позиции. К подрядчикам не применяется — у них фиксированная сумма вне общего пула');
            $table->integer('manual_amount_override')->nullable()->comment('Ручной ввод суммы к оплате (копейки) прямо на позиции — взаимоисключающе с manual_percent_override');
            $table->decimal('manual_percent_override', 5, 2)->nullable()->comment('Ручной ввод % от услуги прямо на позиции — взаимоисключающе с manual_amount_override');
            $table->timestamps();

            $table->unique(['work_order_item_id', 'assignee_type', 'assignee_id'], 'woi_assignees_unique');
            $table->index(['assignee_type', 'assignee_id']);
        });

        // Переносим уже назначенных штатных исполнителей — все они по определению
        // Employee, других типов до этой миграции существовать не могло.
        DB::table('work_order_item_employees')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('work_order_item_assignees')->insertOrIgnore([
                    'work_order_item_id' => $row->work_order_item_id,
                    'assignee_type' => Employee::class,
                    'assignee_id' => $row->employee_id,
                    'share_percent' => $row->share_percent,
                    'manual_amount_override' => $row->manual_amount_override,
                    'manual_percent_override' => $row->manual_percent_override,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

        Schema::dropIfExists('work_order_item_employees');
    }

    public function down(): void
    {
        Schema::create('work_order_item_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2)->nullable();
            $table->integer('manual_amount_override')->nullable();
            $table->decimal('manual_percent_override', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['work_order_item_id', 'employee_id']);
        });

        // Подрядчики при откате теряются — в старой схеме их попросту негде
        // хранить (FK на employees). Штатные исполнители возвращаются как были.
        DB::table('work_order_item_assignees')
            ->where('assignee_type', Employee::class)
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('work_order_item_employees')->insertOrIgnore([
                    'work_order_item_id' => $row->work_order_item_id,
                    'employee_id' => $row->assignee_id,
                    'share_percent' => $row->share_percent,
                    'manual_amount_override' => $row->manual_amount_override,
                    'manual_percent_override' => $row->manual_percent_override,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

        Schema::dropIfExists('work_order_item_assignees');
    }
};

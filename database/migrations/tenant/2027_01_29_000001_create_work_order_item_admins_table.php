<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тот же переход 1:0..1 -> многие-ко-многим, что и у work_order_admins
 * (миграция 2027_01_29_000000), но на уровне позиции: work_order_items.
 * admin_override (inherit/custom/none) остаётся как есть — теперь при
 * admin_override=custom администраторов позиции может быть несколько,
 * их список и хранится в этом пивоте вместо единственной колонки
 * admin_employee_id. Переносим только записи с уже выставленным custom-
 * админом — для inherit/none admin_employee_id и так пуст или неактуален.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_item_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2)->nullable()->comment('Доля начисления между админами позиции; null = равная доля');
            $table->integer('manual_amount_override')->nullable()->comment('Ручная сумма (копейки), взаимоисключающе с manual_percent_override');
            $table->decimal('manual_percent_override', 5, 2)->nullable()->comment('Ручной % от базы, взаимоисключающе с manual_amount_override');
            $table->timestamps();

            $table->unique(['work_order_item_id', 'employee_id']);
        });

        $now = now();
        DB::table('work_order_items')
            ->where('admin_override', 'custom')
            ->whereNotNull('admin_employee_id')
            ->get(['id', 'admin_employee_id'])
            ->each(function ($item) use ($now) {
                DB::table('work_order_item_admins')->insertOrIgnore([
                    'work_order_item_id' => $item->id,
                    'employee_id' => $item->admin_employee_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropForeign(['admin_employee_id']);
            $table->dropColumn('admin_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->foreignId('admin_employee_id')->nullable()->after('admin_override')->constrained('employees')->nullOnDelete();
        });

        DB::table('work_order_item_admins')
            ->select('work_order_item_id', DB::raw('MIN(employee_id) as employee_id'))
            ->groupBy('work_order_item_id')
            ->get()
            ->each(fn ($row) => DB::table('work_order_items')->where('id', $row->work_order_item_id)->update(['admin_employee_id' => $row->employee_id]));

        Schema::dropIfExists('work_order_item_admins');
    }
};

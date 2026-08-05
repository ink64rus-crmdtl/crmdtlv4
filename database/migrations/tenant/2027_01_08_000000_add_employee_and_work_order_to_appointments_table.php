<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблица appointments уже существовала как заготовка (2026_07_28_131745).
     * Добавляем поля, нужные для Фазы 9.2 — назначенного мастера и ссылку
     * на заказ-наряд, создаваемый при конвертации записи (Фаза 9.4).
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('vehicle_id')->constrained('employees')->nullOnDelete()->comment('Назначенный мастер');
            $table->foreignId('work_order_id')->nullable()->after('employee_id')->constrained('work_orders')->nullOnDelete()->comment('Заполняется при конвертации записи в заказ-наряд');
        });

        $now = now();

        $statuses = [
            ['value' => 'scheduled', 'label' => 'Запланирована', 'color' => 'info', 'sort_order' => 0],
            ['value' => 'confirmed', 'label' => 'Подтверждена', 'color' => 'primary', 'sort_order' => 1],
            ['value' => 'no_show', 'label' => 'Не пришел', 'color' => 'danger', 'sort_order' => 2],
            ['value' => 'converted', 'label' => 'Оформлена в заказ', 'color' => 'success', 'sort_order' => 3],
            ['value' => 'cancelled', 'label' => 'Отменена', 'color' => 'gray', 'sort_order' => 4],
        ];

        foreach ($statuses as $status) {
            DB::table('lookups')->insertOrIgnore([
                'type' => 'appointment_status',
                'value' => $status['value'],
                'label' => $status['label'],
                'color' => $status['color'],
                'sort_order' => $status['sort_order'],
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('lookups')->where('type', 'appointment_status')->delete();

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};

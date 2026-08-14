<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенесено из
 * 2026_08_05_054414_add_employee_id_to_work_order_items_table.php —
 * там work_order_items ещё не существовала на новой базе тенанта
 * (создаётся 2026_12_31_999999, позже по порядку выполнения). hasColumn-
 * проверка — на уже смигрировавших тенантах обычный addColumn упал бы с
 * "duplicate column" при первом же tenants:migrate после обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_order_items', 'employee_id')) {
            Schema::table('work_order_items', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->after('work_order_id')->constrained('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подрядчика (Client с ролью «Подрядчик») можно назначить исполнителем
 * записи в календаре — раньше Appointment.employee_id был жёстким FK
 * только на сотрудника (см. CLAUDE.md, "ОТЛОЖЕНО СОЗНАТЕЛЬНО"). Решение —
 * НЕ полиморфный assignee_type/assignee_id без FK (как у
 * work_order_item_assignees — там оправдано коллекцией из МНОГИХ
 * исполнителей на позицию), а второй nullable FK-столбец рядом с
 * employee_id, тот же паттерн, что уже есть у payrolls.employee_id/
 * client_id (Payroll::payee()) — запись имеет РОВНО ОДНОГО исполнителя,
 * поэтому пара настоящих FK с nullOnDelete строго лучше: не жертвует
 * целостностью ради гибкости, которая здесь не нужна. Инвариант "заполнен
 * максимум один из двух" — на уровне приложения (AppointmentController::
 * validateAppointment()), как и у Payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('contractor_id')->nullable()->after('employee_id')
                ->constrained('clients')->nullOnDelete()
                ->comment('Исполнитель-подрядчик — альтернатива employee_id, заполнен максимум один из двух');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_id');
        });
    }
};

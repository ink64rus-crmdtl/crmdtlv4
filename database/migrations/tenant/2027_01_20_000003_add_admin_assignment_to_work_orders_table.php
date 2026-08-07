<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('vehicle_id')->constrained('users')->nullOnDelete();
            $table->foreignId('default_admin_employee_id')->nullable()->after('created_by')->constrained('employees')->nullOnDelete()->comment('Администратор заказа по умолчанию — для расчёта ЗП по каждой позиции, если не переопределён на уровне позиции');
            $table->string('admin_assignment_mode')->default('auto')->after('default_admin_employee_id')->comment('auto — проставлен автоматически по создателю заказа, manual — выбран менеджером вручную и не должен перезаписываться автоматикой');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('default_admin_employee_id');
            $table->dropColumn('admin_assignment_mode');
        });
    }
};

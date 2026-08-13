<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Подрядчик (Client с ролью «Подрядчик») теперь может быть исполнителем услуги
 * (см. work_order_item_assignees, соседняя миграция), значит ему нужно уметь
 * начислять и выплачивать деньги. Начисление (payrolls) и ставка
 * (payroll_rules) до этого умели ссылаться ТОЛЬКО на employees.
 *
 * Схема: employee_id и client_id — оба nullable, заполнен ровно один из них.
 * CHECK-констрейнт сознательно не ставим (на MySQL 5.7 он игнорируется молча,
 * а поддерживать разное поведение на разных версиях хуже, чем одна явная
 * проверка в коде) — инвариант держат Payroll::scopeForAssignee()/
 * PayrollCalculationService, единственные места, создающие эти строки.
 *
 * У подрядчика НЕТ должности (position_id) — он не сотрудник, и каскад
 * «персональная ставка → ставка должности» для него обрывается на первом
 * шаге: ставка ищется только по client_id (см. PayrollCalculationService::
 * resolveRate()). Поэтому колонка position_id для подрядчицких правил всегда
 * пуста, и это не потеря функциональности, а отсутствие самого понятия.
 */
return new class extends Migration
{
    public function up(): void
    {
        // employee_id был NOT NULL — снимаем ограничение, предварительно убрав
        // внешний ключ (MySQL не даёт менять тип колонки под действующим FK).
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->after('employee_id')->constrained('clients')->cascadeOnDelete()->comment('Получатель-подрядчик; взаимоисключающе с employee_id');
        });

        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('employee_id')->constrained('clients')->cascadeOnDelete()->comment('Персональная ставка подрядчика (Client с ролью «Подрядчик»); взаимоисключающе с employee_id/position_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        // Начисления подрядчикам при откате теряются — в старой схеме
        // employee_id NOT NULL, хранить их негде.
        DB::table('payrolls')->whereNull('employee_id')->delete();

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropForeign(['employee_id']);
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};

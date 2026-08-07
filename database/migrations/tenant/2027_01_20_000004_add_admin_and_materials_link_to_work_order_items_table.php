<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->string('admin_override')->default('inherit')->after('employee_id')->comment('inherit — берётся default_admin_employee_id заказа, custom — свой админ на позиции, none — без администратора на этой позиции');
            $table->foreignId('admin_employee_id')->nullable()->after('admin_override')->constrained('employees')->nullOnDelete()->comment('Используется только при admin_override=custom');
            $table->foreignId('linked_item_id')->nullable()->after('admin_employee_id')->constrained('work_order_items')->nullOnDelete()->comment('Для позиции-материала: к какой позиции-услуге он привязан — нужно для вычета стоимости материалов из базы расчёта ЗП именно этой услуги');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_employee_id');
            $table->dropConstrainedForeignId('linked_item_id');
            $table->dropColumn('admin_override');
        });
    }
};

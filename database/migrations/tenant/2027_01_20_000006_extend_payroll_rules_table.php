<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('position_id')->constrained('employees')->cascadeOnDelete()->comment('Персональная ставка сотрудника — выше приоритетом, чем ставка должности');
            $table->foreignId('service_category_id')->nullable()->after('service_id')->constrained('service_categories')->cascadeOnDelete()->comment('Ставка на группу услуг — используется, когда конкретная услуга не задана');
            $table->boolean('is_default_for_unlisted')->default(false)->after('service_category_id')->comment('Ставка по умолчанию для услуг, введённых вручную вне справочника (itemable_id пуст)');
            $table->foreignId('branch_id')->nullable()->after('is_default_for_unlisted')->constrained('branches')->cascadeOnDelete()->comment('null — ставка действует на все филиалы; иначе только на указанный');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('service_category_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('is_default_for_unlisted');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Дефолт для WorkOrderItem.affects_payroll при добавлении этого
            // товара как материала на услугу (CLAUDE.md, «Материалы на услугу»).
            $table->boolean('affects_payroll_by_default')->default(true)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('affects_payroll_by_default');
        });
    }
};

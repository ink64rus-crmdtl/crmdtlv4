<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_item_employees', function (Blueprint $table) {
            $table->decimal('share_percent', 5, 2)->nullable()->after('employee_id')->comment('Доля объёма работ в бригаде; null = равная доля между всеми исполнителями позиции');
            $table->integer('manual_amount_override')->nullable()->after('share_percent')->comment('Ручной ввод суммы к оплате (копейки) прямо на позиции — взаимоисключающе с manual_percent_override');
            $table->decimal('manual_percent_override', 5, 2)->nullable()->after('manual_amount_override')->comment('Ручной ввод % от услуги прямо на позиции — взаимоисключающе с manual_amount_override');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_item_employees', function (Blueprint $table) {
            $table->dropColumn(['share_percent', 'manual_amount_override', 'manual_percent_override']);
        });
    }
};

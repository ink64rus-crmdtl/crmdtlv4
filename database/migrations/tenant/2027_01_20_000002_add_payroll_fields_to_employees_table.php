<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('secondary_position_id')->nullable()->after('position_id')->constrained('positions')->nullOnDelete()->comment('Совмещаемая должность — для расчёта ЗП, когда сотрудник выполняет работу не по основной роли');
            $table->integer('salary_amount')->nullable()->after('secondary_position_id')->comment('Оклад в минимальных единицах валюты (копейки), начисляется периодически, независимо от заказов');
            $table->decimal('self_employed_tax_percent', 5, 2)->nullable()->after('salary_amount')->comment('Личная ставка компенсации налога для самозанятых; null = взять дефолт тенанта из Setting');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secondary_position_id');
            $table->dropColumn(['salary_amount', 'self_employed_tax_percent']);
        });
    }
};

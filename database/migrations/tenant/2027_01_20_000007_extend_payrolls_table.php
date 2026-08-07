<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('work_order_item_id')->nullable()->after('work_order_id')->constrained('work_order_items')->nullOnDelete()->comment('Конкретная позиция-услуга, ставшая основанием начисления — для трассировки');
            $table->string('role')->default('worker')->after('type')->comment('admin, worker, salary, manual — по какой роли в расчёте начислено');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_item_id');
            $table->dropColumn('role');
        });
    }
};

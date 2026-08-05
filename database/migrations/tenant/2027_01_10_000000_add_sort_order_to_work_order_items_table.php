<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('total');
        });

        // Бэкафилл: сохраняем текущий порядок (по id) как стартовый sort_order,
        // по каждому заказу отдельно — иначе после сортировки существующие позиции
        // все окажутся с sort_order=0 и порядок станет неопределённым.
        DB::table('work_order_items')
            ->select('id', 'work_order_id')
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get()
            ->groupBy('work_order_id')
            ->each(function ($items) {
                foreach ($items->values() as $index => $item) {
                    DB::table('work_order_items')->where('id', $item->id)->update(['sort_order' => $index]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

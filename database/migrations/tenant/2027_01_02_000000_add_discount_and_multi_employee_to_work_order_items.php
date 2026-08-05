<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->integer('discount_amount')->default(0)->after('price')->comment('Item-level discount in minimal currency units');
        });

        Schema::create('work_order_item_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['work_order_item_id', 'employee_id']);
        });

        // Переносим уже назначенных единичных исполнителей в новую таблицу (многие-ко-многим)
        $now = now();
        DB::table('work_order_items')
            ->whereNotNull('employee_id')
            ->get(['id', 'employee_id'])
            ->each(function ($item) use ($now) {
                DB::table('work_order_item_employees')->insertOrIgnore([
                    'work_order_item_id' => $item->id,
                    'employee_id' => $item->employee_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_item_employees');

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};

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
        Schema::table('lookups', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('color');
            $table->boolean('is_system')->default(false)->comment('System entries cannot be edited or deleted by the user')->after('sort_order');
            $table->string('label')->nullable()->comment('Optional human-readable label, used when value must stay a stable code (e.g. work order statuses)')->after('is_system');
        });

        $now = now();

        $statuses = [
            ['value' => 'new', 'label' => 'Новый', 'color' => 'info', 'sort_order' => 0],
            ['value' => 'in_progress', 'label' => 'В работе', 'color' => 'warning', 'sort_order' => 1],
            ['value' => 'ready', 'label' => 'Готов', 'color' => 'success', 'sort_order' => 2],
            ['value' => 'completed', 'label' => 'Выдан', 'color' => 'gray', 'sort_order' => 3],
            ['value' => 'canceled', 'label' => 'Отменен', 'color' => 'danger', 'sort_order' => 4],
        ];

        foreach ($statuses as $status) {
            DB::table('lookups')->insertOrIgnore([
                'type' => 'work_order_status',
                'value' => $status['value'],
                'label' => $status['label'],
                'color' => $status['color'],
                'sort_order' => $status['sort_order'],
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lookups', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_system', 'label']);
        });
    }
};

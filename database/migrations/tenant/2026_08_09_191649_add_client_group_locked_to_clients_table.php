<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // true — грейд выбран менеджером вручную, LoyaltyGradeService::evaluate()
            // такого клиента не трогает при автоподборе (ручной выбор в приоритете).
            $table->boolean('client_group_locked')->default(false)->after('client_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('client_group_locked');
        });
    }
};

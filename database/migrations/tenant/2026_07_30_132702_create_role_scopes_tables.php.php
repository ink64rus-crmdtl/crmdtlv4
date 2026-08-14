<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_branches', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['role_id', 'branch_id']);
        });

        Schema::create('role_legal_entities', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->primary(['role_id', 'legal_entity_id']);
        });

        Schema::create('role_business_directions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();
            $table->primary(['role_id', 'business_direction_id']);
        });

        // role_warehouses/role_accounts — см. отдельную более позднюю
        // миграцию 2026_12_31_900014_create_user_warehouses_and_user_accounts_tables.php.
        // Раньше были здесь, но warehouses/accounts создаются миграциями
        // 2026_12_31_900005/900012 — те идут ПОСЛЕ этой по порядку
        // выполнения, поэтому FK здесь падал с "Failed to open the
        // referenced table" на КАЖДОЙ новой базе тенанта (регистрация
        // нового тенанта была сломана). Уже смигрировавшие тенанты этот
        // файл повторно не выполняют — на них не влияет.
    }

    public function down(): void
    {
        Schema::dropIfExists('role_business_directions');
        Schema::dropIfExists('role_legal_entities');
        Schema::dropIfExists('role_branches');
    }
};

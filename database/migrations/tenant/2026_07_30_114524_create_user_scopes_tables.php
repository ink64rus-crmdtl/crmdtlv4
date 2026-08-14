<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });

        Schema::create('user_legal_entities', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->primary(['user_id', 'legal_entity_id']);
        });

        Schema::create('user_business_directions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();
            $table->primary(['user_id', 'business_direction_id']);
        });

        // user_warehouses/user_accounts — см. отдельную более позднюю
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
        Schema::dropIfExists('user_business_directions');
        Schema::dropIfExists('user_legal_entities');
        Schema::dropIfExists('user_branches');
    }
};

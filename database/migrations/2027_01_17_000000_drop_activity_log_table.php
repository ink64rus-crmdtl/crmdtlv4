<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * activity_log была по ошибке создана в центральной (landlord) БД
     * миграцией 2026_06_22_193039_create_activity_log_table — но история
     * взаимодействия (заказы, клиенты, записи) относится к конкретному
     * тенанту и обязана жить в его собственной БД (см. CLAUDE.md, п.0).
     * Таблица центрально ни разу не использовалась (0 строк) — переносим
     * её в database/migrations/tenant/.
     */
    public function up(): void
    {
        Schema::dropIfExists('activity_log');
    }

    public function down(): void
    {
        Schema::create('activity_log', function ($table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
};

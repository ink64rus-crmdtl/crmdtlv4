<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблица пакета spatie/laravel-activitylog — история взаимодействия и
     * изменений (лента на "полных карточках" Клиента/Авто/Сотрудника/Заказ-
     * наряда). Перенесена сюда из центральной БД, где была по ошибке — это
     * данные конкретного тенанта, не landlord-уровня (см. CLAUDE.md, п.0).
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};

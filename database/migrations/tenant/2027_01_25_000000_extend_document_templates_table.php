<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 12 — настройки нумерации документа: префикс ("АКТ-") и признак
 * ежегодного сброса счётчика (стандартная практика РФ: "Акт №1 от 2026",
 * счётчик начинается заново с наступлением нового года). Сам счётчик — в
 * отдельной таблице document_numerators (атомарный инкремент), не здесь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('number_prefix')->nullable()->after('entity_type');
            $table->boolean('number_reset_yearly')->default(true)->after('number_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['number_prefix', 'number_reset_yearly']);
        });
    }
};

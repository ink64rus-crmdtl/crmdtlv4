<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 12 — атомарный счётчик номеров документов. Отдельная сущность, не
 * COUNT(*) по documents: пропуски номеров при удалении документа — норма
 * (так делают Bitrix24/1С), а пересчёт вручную легко даёт дублирующиеся
 * номера при параллельной генерации. year — 0 (НЕ null), если у шаблона
 * number_reset_yearly=false (сквозная нумерация без сброса по годам):
 * MySQL считает NULL уникальным относительно других NULL в unique-индексе,
 * так что с null несколько параллельных генераций могли бы завести по
 * второй строке нумератора на одну и ту же связку и разойтись в номерах.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_numerators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['legal_entity_id', 'document_template_id', 'year'], 'document_numerators_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_numerators');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (landlord) БД. Библиотека эталонных шаблонов документов по
 * странам — администратор платформы заводит их в /admin, тенант копирует
 * понравившийся себе как стартовую точку (App\Services\Documents\
 * PlatformDocumentTemplateService::import()) — снепшот, не живая ссылка,
 * дальше это обычный тенантский DocumentTemplate. Схема зеркалит
 * тенантскую document_templates (database/migrations/tenant/
 * 2026_12_31_900041_...), но БЕЗ number_prefix/number_reset_yearly —
 * нумерация документов сугубо тенантская настройка, библиотечный шаблон её
 * не диктует.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->nullable()->index(); // null = «для всех стран»
            $table->string('name');
            $table->string('entity_type')->index();
            $table->string('format')->default('html');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_document_templates');
    }
};

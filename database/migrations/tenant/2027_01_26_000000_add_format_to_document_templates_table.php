<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Улучшение конструктора шаблонов: 'docx' — шаблон загружен как .docx и
 * сконвертирован в HTML один раз при сохранении (App\Services\Documents\
 * DocxToHtmlConverter) — чисто информационное поле (какой UI показать в
 * форме редактирования), на генерацию документов не влияет: body всегда
 * HTML вне зависимости от происхождения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('format')->default('html')->after('entity_type');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Заменить документ" (DocumentController::regenerateAsNew()) создаёт новый
 * документ с текущими данными, но старый не удаляет — вместо этого помечает
 * его superseded_by_document_id, чтобы UI показывал не активное предупреждение
 * "устарел, перегенерируйте", а нейтральную серую пометку "заменён документом
 * №X" без повторного действия. nullOnDelete — если новый документ впоследствии
 * удалят, старый должен автоматически вернуться в обычное "устарел" состояние,
 * а не остаться с битой ссылкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('superseded_by_document_id')->nullable()->after('number')
                ->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_document_id');
        });
    }
};

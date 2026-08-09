<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Явный выбор юрлица на самом заказе — независимо от точки (branch_id).
 * Нужен именно из-за branch_legal_entity (соседняя миграция): раз точка
 * может иметь несколько юрлиц одновременно, системе неоткуда больше узнать,
 * от какого конкретно выставлять документ по ЭТОМУ заказу — раньше это
 * однозначно выводилось из branch->legalEntity (была ровно одна), теперь
 * нет. Nullable — точка может работать вообще без юрлица (см.
 * DocumentPlaceholderService::resolveLegalEntity() — тогда просто пустые
 * реквизиты в документе, не жёсткая ошибка) или иметь ровно одно, тогда это
 * поле можно не трогать явно, фолбэк резолвит его сам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->nullable()->after('branch_id')->constrained('legal_entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['legal_entity_id']);
            $table->dropColumn('legal_entity_id');
        });
    }
};

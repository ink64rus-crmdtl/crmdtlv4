<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Точка (Branch) может выставлять документы от нескольких юрлиц (и юрлицо
 * может обслуживать несколько точек) — было "один-к-нулю-или-одному"
 * (branches.legal_entity_id, единственная колонка), стало многие-ко-многим.
 * Причина смены: одна физическая точка нередко реально работает и от ИП, и
 * от ООО одновременно (по ситуации), а старая схема такое в принципе не
 * могла выразить — приходилось бы заводить лишние "точки-дубликаты" под
 * каждое юрлицо. См. WorkOrder.legal_entity_id (соседняя миграция) — именно
 * оттуда теперь берётся КОНКРЕТНОЕ юрлицо документа, привязка точка↔юрлицо
 * здесь только про "какие юрлица вообще доступны для выбора на этой точке".
 *
 * up() переносит существующие связи в пивот и удаляет исходную колонку —
 * сознательное отступление от "никогда не удаляй колонки" (CLAUDE.md, п.8):
 * это не случайная потеря данных, а прямо запрошенная и подтверждённая
 * архитектурная замена связи, данные переносятся, а не выбрасываются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_legal_entity', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->primary(['branch_id', 'legal_entity_id']);
        });

        $links = DB::table('branches')
            ->whereNotNull('legal_entity_id')
            ->select('id as branch_id', 'legal_entity_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        if (!empty($links)) {
            DB::table('branch_legal_entity')->insert($links);
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['legal_entity_id']);
            $table->dropColumn('legal_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->nullable()->after('id')->constrained('legal_entities')->nullOnDelete();
        });

        // М:М -> 1:0..1 необратимо без потери информации (branch с двумя
        // юрлицами не может вернуться к одной колонке) — восстанавливаем
        // первую попавшуюся связь на branch, остальные при откате теряются.
        DB::table('branch_legal_entity')
            ->select('branch_id', DB::raw('MIN(legal_entity_id) as legal_entity_id'))
            ->groupBy('branch_id')
            ->get()
            ->each(fn ($row) => DB::table('branches')->where('id', $row->branch_id)->update(['legal_entity_id' => $row->legal_entity_id]));

        Schema::dropIfExists('branch_legal_entity');
    }
};

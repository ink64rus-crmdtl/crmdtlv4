<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 17 — воронки продаж. Несколько воронок заводим СРАЗУ (решение владельца):
 * продажа плёнки и оптовые поставки идут по принципиально разным стадиям, а
 * заложить это в схему потом кратно дороже. При одной воронке переключатель
 * в UI просто не показывается.
 *
 * business_direction_id — именно здесь, а не колонкой на сделке: у сделки
 * направление ровно одно и задано явно через её воронку (в отличие от заказ-наряда,
 * который может содержать позиции сразу нескольких направлений — см. CLAUDE.md §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->foreignId('business_direction_id')
                ->nullable()
                ->constrained('business_directions')
                ->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};

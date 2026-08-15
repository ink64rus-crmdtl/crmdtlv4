<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стадии воронки. Порядок — drag-n-drop, тем же паттерном, что уже работает
 * у статусов заказ-нарядов (Lookup type=work_order_status + reorder()).
 *
 * type — не косметика, а поведение: open (сделка в работе), won (успех,
 * автопереход при оплате связанного заказа), lost (проигрыш, требует причины).
 * Стадии won/lost неудаляемы в принципе — на них завязаны отчёты воронки.
 *
 * probability — для взвешенного прогноза (сумма × вероятность), приём HubSpot.
 * rotting_days — норматив «протухания»: если сделка лежит в стадии дольше,
 * карточка помечается. Считается НА ЛЕТУ из deals.stage_entered_at,
 * фонового джоба намеренно нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();

            $table->string('name');
            $table->string('color')->default('gray')->comment('Токен темы: gray/info/warning/success/danger/primary');
            $table->string('type')->default('open')->comment('open|won|lost');

            $table->unsignedTinyInteger('probability')->default(0)->comment('0-100, для взвешенного прогноза');
            $table->unsignedSmallInteger('rotting_days')->nullable()->comment('Норматив «протухания», null = не следим');

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['pipeline_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};

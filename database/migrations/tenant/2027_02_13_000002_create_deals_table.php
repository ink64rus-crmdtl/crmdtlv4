<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сделка (Фаза 17) — ОТДЕЛЬНАЯ сущность между Клиентом и Заказ-нарядом:
 * переговоры о намерении. Продолжение принципа Фазы 9 «Запись (намерение)
 * ≠ Заказ-наряд (факт)», на шаг раньше.
 *
 * Сделка НЕ ТРОГАЕТ склад и финансы — ровно как Appointment: правки/отмены
 * сделки не должны требовать отката складских или финансовых операций,
 * потому что до конвертации их попросту нет.
 *
 * client_id ОБЯЗАТЕЛЕН (решение владельца): анонимных сделок «только с
 * телефоном» не делаем, иначе телефон — главный признак дубля в системе
 * (CLAUDE.md §7) — окажется в двух разных местах и поиск дублей сломается.
 * Лид заводится сразу как Client с is_lead=true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();

            // Изоляция — BranchScope (CLAUDE.md §0/§8, критичный tenant-isolation код).
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // Явный выбор юрлица — тот же паттерн, что у WorkOrder: у локации
            // может быть несколько юрлиц, однозначно вывести нельзя.
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->foreignId('pipeline_stage_id')->constrained('pipeline_stages')->cascadeOnDelete();

            // Ответственный менеджер — понятия «кто ведёт клиента» в системе
            // раньше не было вообще (у заказа есть только created_by).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->integer('amount')->default(0)->comment('Сумма сделки в минимальных единицах валюты (копейки)');
            $table->date('expected_close_date')->nullable();

            // Момент входа в текущую стадию — основа «протухания» (deal rotting).
            $table->timestamp('stage_entered_at')->nullable();

            // Источник — переиспользуем существующий справочник Lookup(type=client_source).
            $table->foreignId('source_lookup_id')->nullable()->constrained('lookups')->nullOnDelete();
            // Причина проигрыша — новый Lookup(type=deal_loss_reason), заполняется при переходе в стадию type=lost.
            $table->foreignId('loss_reason_lookup_id')->nullable()->constrained('lookups')->nullOnDelete();

            // Двусторонние связи с уже существующими сущностями.
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Доска фильтрует прежде всего по локации + стадии.
            $table->index(['branch_id', 'pipeline_stage_id']);
            $table->index(['pipeline_id', 'pipeline_stage_id']);
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};

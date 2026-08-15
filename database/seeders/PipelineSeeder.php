<?php

namespace Database\Seeders;

use App\Models\Lookup;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * Фаза 17 — стартовая воронка «Продажи» со стадиями по умолчанию.
 * Идемпотентен (updateOrCreate/firstOrCreate), поэтому его безопасно
 * прогонять и на уже живом тенанте, чтобы открыть ему модуль.
 *
 * Стадии подобраны под детейлинг: обращение → квалификация → замер/расчёт →
 * КП отправлено → согласование → успех/проигрыш. Тенант правит их под себя
 * в Настройках, это только рабочая заготовка, а не жёсткий стандарт.
 */
class PipelineSeeder extends Seeder
{
    public function run(): void
    {
        $pipeline = Pipeline::firstOrCreate(
            ['name' => 'Продажи'],
            ['is_default' => true, 'is_active' => true, 'sort_order' => 0]
        );

        // sort_order, name, color (токен темы), type, probability, rotting_days
        $stages = [
            ['Новое обращение', 'gray', PipelineStage::TYPE_OPEN, 10, 3],
            ['Квалификация', 'info', PipelineStage::TYPE_OPEN, 25, 5],
            ['Замер / расчёт', 'primary', PipelineStage::TYPE_OPEN, 45, 7],
            ['КП отправлено', 'warning', PipelineStage::TYPE_OPEN, 65, 5],
            ['Согласование', 'warning', PipelineStage::TYPE_OPEN, 80, 5],
            ['Успех', 'success', PipelineStage::TYPE_WON, 100, null],
            ['Проигрыш', 'danger', PipelineStage::TYPE_LOST, 0, null],
        ];

        foreach ($stages as $index => [$name, $color, $type, $probability, $rotting]) {
            PipelineStage::firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'name' => $name],
                [
                    'color' => $color,
                    'type' => $type,
                    'probability' => $probability,
                    'rotting_days' => $rotting,
                    'sort_order' => $index,
                    'is_active' => true,
                ]
            );
        }

        // Причины проигрыша — обычный справочник Lookup, как источники клиентов.
        // Не системные (is_system): тенант вправе переименовать их под свой рынок.
        $lossReasons = ['Дорого', 'Выбрал конкурента', 'Передумал', 'Не дозвонились', 'Не наша услуга'];

        foreach ($lossReasons as $index => $label) {
            Lookup::firstOrCreate(
                ['type' => 'deal_loss_reason', 'value' => 'loss_'.($index + 1)],
                ['label' => $label, 'sort_order' => $index, 'is_active' => true, 'is_system' => false]
            );
        }

        // Типы задач (этап 2) — та же схема, что и статусы заказ-нарядов:
        // обычная строка на Task.type, сверяемая со справочником, не FK.
        $taskTypes = ['Звонок', 'Встреча', 'Сообщение', 'Другое'];

        foreach ($taskTypes as $index => $label) {
            Lookup::firstOrCreate(
                ['type' => 'task_type', 'value' => 'task_type_'.($index + 1)],
                ['label' => $label, 'sort_order' => $index, 'is_active' => true, 'is_system' => false]
            );
        }
    }
}

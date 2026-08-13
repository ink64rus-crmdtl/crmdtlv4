<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * До сих пор LookupController::update() блокировал ЛЮБУЮ правку is_system
 * записи целиком — в том числе переименование. Теперь для системных ролей
 * клиента (is_system=true, type=client_role) переименование разрешается
 * (см. LookupController::update()), а удаление остаётся запрещено даже
 * администратору.
 *
 * Но `value` этих трёх ролей до сих пор был захардкожен в PHP: WorkOrderController
 * и PayrollSettingsController сравнивали ->where('value', 'Подрядчик') буквальным
 * русским текстом. Разрешить переименование значения напрямую значило бы молча
 * рвать эту связь при первом же ребрендинге роли ("Подрядчик" → "Партнёр" и т.п.) —
 * право быть исполнителем услуги просто пропало бы без единой ошибки.
 *
 * Решение — тот же приём, что уже применён к work_order_status/appointment_status:
 * `value` становится стабильным латинским кодом (никогда не меняется, не показывается
 * пользователю), `label` — редактируемым отображаемым текстом. CONTRACTOR_ROLE
 * теперь сравнивается по value-коду, а не по тексту, поэтому переименование label
 * больше ничего не ломает.
 *
 * Безопасно матчить по текущему value: с момента создания этих ролей (миграция
 * 2027_01_30) update() блокировал их правку полностью, так что value гарантированно
 * ещё не был переименован ни на одном тенанте.
 */
return new class extends Migration
{
    private const MAP = [
        'Клиент' => ['value' => 'client', 'label' => 'Клиент'],
        'Подрядчик' => ['value' => 'contractor', 'label' => 'Подрядчик'],
        'Поставщик' => ['value' => 'supplier', 'label' => 'Поставщик'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::MAP as $currentValue => $target) {
            DB::table('lookups')
                ->where('type', 'client_role')
                ->where('is_system', true)
                ->where('value', $currentValue)
                ->update([
                    'value' => $target['value'],
                    'label' => $target['label'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (self::MAP as $originalValue => $target) {
            DB::table('lookups')
                ->where('type', 'client_role')
                ->where('is_system', true)
                ->where('value', $target['value'])
                ->update([
                    'value' => $originalValue,
                    'label' => null,
                    'updated_at' => $now,
                ]);
        }
    }
};

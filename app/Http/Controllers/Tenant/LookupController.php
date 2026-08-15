<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Service;
use App\Models\VehicleModel;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LookupController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        // Проверяем, нет ли уже такого значения в этом справочнике (case-insensitive)
        $existing = Lookup::where('type', $validated['type'])
            ->where('value', $validated['value'])
            ->first();

        if ($existing) {
            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'data' => $existing]);
            }

            return redirect()->back()->withErrors(['value' => 'Такое значение уже существует в справочнике.']);
        }

        $nextSortOrder = (int) Lookup::where('type', $validated['type'])->max('sort_order') + 1;

        $lookup = Lookup::create([
            'type' => $validated['type'],
            'value' => $validated['value'],
            // Форма (и <CreatableSelect>) не даёт отдельного поля "label" —
            // пользователь вводит один текст, он же и есть отображаемое
            // значение. Отдельные value/label (машинный слаг + русский текст)
            // бывают только у записей, заведённых миграцией/сидером
            // (work_order_status и т.п.), которые сюда не попадают.
            'label' => $validated['value'],
            'color' => $validated['color'] ?? null,
            'sort_order' => $nextSortOrder,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Если запрос пришел из Vue-компонента (добавление на лету)
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'data' => $lookup]);
        }

        return redirect()->back()->with('success', 'Запись успешно добавлена в справочник');
    }

    public function update(Request $request, Lookup $lookup)
    {
        // Системные роли клиента (Клиент/Подрядчик/Поставщик) когда-то
        // разрешали переименование (текст уходил в label, value-слаг
        // оставался неизменным — см. миграцию 2027_02_02). Решение отменено:
        // случайная правка текста роли менеджером без понимания последствий
        // сочли более вероятным риском, чем неудобство от невозможности
        // переименовать. Теперь is_system блокирует ЛЮБУЮ правку полностью,
        // без исключений — так же, как у остальных системных справочников.
        // value/label из той миграции трогать не нужно: label уже хранит
        // отображаемый текст, просто больше никем не редактируется.
        if ($lookup->is_system) {
            return redirect()->back()->withErrors(['error' => 'Системную запись нельзя изменить.']);
        }

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        // Проверка на дубликат при переименовании
        $existing = Lookup::where('type', $lookup->type)
            ->where('value', $validated['value'])
            ->where('id', '!=', $lookup->id)
            ->exists();

        if ($existing) {
            return redirect()->back()->withErrors(['value' => 'Такое значение уже существует в справочнике.']);
        }

        $lookup->update([
            'value' => $validated['value'],
            // Backfill, НЕ безусловная перезапись: у записей вида
            // work_order_status label и value специально разведены сидером
            // (машинный слаг 'new' → человеческий 'Новый') — затирать уже
            // заполненный label текстом из value было бы регрессом.
            // Пусто — значит запись создана через этот же контроллер
            // (quick-add, value===label по построению, см. store()) и её
            // безопасно донастроить сейчас, раз баг когда-то оставил label пустым.
            'label' => $lookup->label ?: $validated['value'],
            'color' => $validated['color'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Запись обновлена');
    }

    public function destroy(Lookup $lookup)
    {
        // Намеренно БЕЗ обхода для auth()->user()->isAdmin(): в отличие от
        // обычных status/workflow-блокировок (см. CLAUDE.md, "Право
        // администратора на удаление без ограничений"), это защита
        // целостности данных, а не просто пометка статуса — системная роль
        // клиента (Клиент/Подрядчик/Поставщик) захардкожена в бизнес-логике
        // (CONTRACTOR_ROLE), и её удаление сломает право быть исполнителем
        // услуги без единой ошибки в логах. Системную запись нельзя ни
        // удалить, ни изменить — ни то, ни другое не должно быть доступно
        // даже пользователю с ролью admin (см. update()).
        if ($lookup->is_system) {
            return redirect()->back()->withErrors(['error' => 'Системную запись нельзя удалить.']);
        }

        if ($lookup->type === 'work_order_status' && WorkOrder::where('status', $lookup->value)->exists()) {
            return redirect()->back()->withErrors(['error' => 'Статус используется в заказ-нарядах и не может быть удалён.']);
        }

        if ($lookup->type === 'vehicle_body' || $lookup->type === 'vehicle_class') {
            $column = $lookup->type === 'vehicle_body' ? 'body_type' : 'category';

            if (VehicleModel::where($column, $lookup->value)->exists()) {
                return redirect()->back()->withErrors(['error' => 'Значение используется в моделях автомобилей и не может быть удалено.']);
            }

            // Ключи JSON-матрицы цен — произвольные строки из справочника, поэтому сравниваем
            // в PHP, а не через JSON_CONTAINS_PATH (значение lookup нельзя безопасно подставить
            // в JSON-путь на уровне SQL — оно не экранируется от кавычек).
            $usedInPrices = Service::whereNotNull('prices')
                ->get(['id', 'prices'])
                ->contains(fn (Service $service) => array_key_exists($lookup->value, $service->prices ?? []));

            if ($usedInPrices) {
                return redirect()->back()->withErrors(['error' => 'Для этого значения задана цена в прайс-листе — сначала уберите её из услуг.']);
            }
        }

        $lookup->delete();

        return redirect()->back()->with('success', 'Запись удалена из справочника');
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:lookups,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['ids'] as $index => $id) {
                Lookup::where('id', $id)
                    ->where('type', $validated['type'])
                    ->update(['sort_order' => $index]);
            }
        });

        return redirect()->back()->with('success', 'Порядок обновлён');
    }
}

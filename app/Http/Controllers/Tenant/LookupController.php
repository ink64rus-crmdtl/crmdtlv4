<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\WorkOrder;
use App\Models\VehicleModel;
use App\Models\Service;
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
            'color' => $validated['color'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Запись обновлена');
    }

    public function destroy(Lookup $lookup)
    {
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
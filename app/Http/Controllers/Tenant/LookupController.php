<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use Illuminate\Http\Request;

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

        $lookup = Lookup::create([
            'type' => $validated['type'],
            'value' => $validated['value'],
            'color' => $validated['color'] ?? null,
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
        $lookup->delete();

        return redirect()->back()->with('success', 'Запись удалена из справочника');
    }
}
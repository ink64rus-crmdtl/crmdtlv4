<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseSettingsController extends Controller
{
    public function index(): Response
    {
        // Читаем текущий режим склада (по умолчанию - раздельный)
        $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';

        return Inertia::render('Settings/Warehouse/Index', [
            'warehouseMode' => $mode,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_mode' => ['required', 'string', 'in:per_branch,shared,mixed'],
        ]);

        Setting::updateOrCreate(
            ['key' => 'warehouse_mode'],
            ['value' => $validated['warehouse_mode']]
        );

        return redirect()->back()->with('success', 'Настройки склада успешно сохранены');
    }
}
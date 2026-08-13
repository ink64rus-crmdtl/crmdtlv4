<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'warehouses' => Warehouse::with('branches:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'owner_type', 'owner_id', 'is_default', 'is_active']),
            'branches' => Branch::forSelect()->get(['id', 'name']),
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

    public function storeWarehouse(Request $request)
    {
        $validated = $this->validateWarehouse($request);

        DB::transaction(function () use ($validated) {
            if ($validated['is_default']) {
                $this->clearOtherDefaults($validated['owner_type'], $validated['owner_id']);
            }

            Warehouse::create($validated);
        });

        return redirect()->back()->with('success', 'Склад добавлен');
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse)
    {
        $validated = $this->validateWarehouse($request);

        DB::transaction(function () use ($validated, $warehouse) {
            if ($validated['is_default']) {
                $this->clearOtherDefaults($validated['owner_type'], $validated['owner_id'], $warehouse->id);
            }

            $warehouse->update($validated);
        });

        return redirect()->back()->with('success', 'Склад обновлён');
    }

    /**
     * Удаление — только если склад нигде реально не использован. В отличие
     * от обычных status/workflow-блокировок (CLAUDE.md, "Право администратора
     * на удаление без ограничений"), это защита целостности склада/финансов
     * (уже прошедшие движения/остатки), поэтому обхода для admin здесь
     * намеренно нет — как и у сверки/закрытия периода.
     */
    public function destroyWarehouse(Warehouse $warehouse)
    {
        if (StockMovement::where('warehouse_id', $warehouse->id)->exists()) {
            return redirect()->back()->withErrors(['error' => 'По складу уже есть движения — удаление невозможно.']);
        }

        if (StockBalance::where('warehouse_id', $warehouse->id)->exists()) {
            return redirect()->back()->withErrors(['error' => 'На складе числятся остатки товаров — сначала спишите их.']);
        }

        if (Product::where('preferred_warehouse_id', $warehouse->id)->exists()) {
            return redirect()->back()->withErrors(['error' => 'Склад выбран как предпочтительный для одного или нескольких товаров — сначала измените это в карточках товаров.']);
        }

        $warehouse->delete();

        return redirect()->back()->with('success', 'Склад удалён');
    }

    private function validateWarehouse(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_type' => ['required', 'string', Rule::in(['branch', 'company'])],
            'owner_id' => ['nullable', 'required_if:owner_type,branch', 'exists:branches,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        // company-склад ни к какой конкретной точке не привязан — owner_id
        // для него должен быть пуст, даже если фронт случайно прислал старое
        // значение при переключении типа туда-обратно.
        $validated['owner_id'] = $validated['owner_type'] === 'branch' ? $validated['owner_id'] : null;
        $validated['is_default'] = $validated['is_default'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;

        return $validated;
    }

    /**
     * "По умолчанию" — единственный склад в своей области (WarehouseResolver
     * читает его так же, как AccountController — is_default_for_invoicing):
     * для company-склада область — вся компания, для branch-склада — эта
     * конкретная точка (на случай, если у точки их несколько через
     * branch_warehouse). Снимаем флаг с остальных ДО сохранения нового,
     * чтобы "по умолчанию" не расползалось на несколько записей сразу.
     */
    private function clearOtherDefaults(string $ownerType, ?int $ownerId, ?int $excludeId = null): void
    {
        Warehouse::where('owner_type', $ownerType)
            ->when($ownerType === 'branch', fn ($q) => $q->where('owner_id', $ownerId))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_default' => false]);
    }
}

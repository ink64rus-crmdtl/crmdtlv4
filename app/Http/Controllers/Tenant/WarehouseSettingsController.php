<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\WarehouseResolver;
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
        $warehouseEnabled = WarehouseResolver::isEnabled();

        return Inertia::render('Settings/Warehouse/Index', [
            'warehouseMode' => $mode,
            'warehouseEnabled' => $warehouseEnabled,
            // Диагностика уже СОХРАНЁННОЙ конфигурации — store() блокирует НОВЫЕ
            // несогласованные сохранения, но не чинит то, что уже могло разойтись
            // раньше (деактивировали единственный склад локации и т.п.), поэтому
            // баннер нужен как отдельный, всегда актуальный источник правды.
            'coverageWarning' => $warehouseEnabled ? $this->modeCoverageError($mode) : null,
            // Автодобавление материала на услугу (CLAUDE.md «Материалы на
            // услугу») — доступно и без склада (себестоимость тогда вводится
            // вручную), поэтому дефолт не зависит от warehouseEnabled.
            'serviceMaterialAutoAddMode' => Setting::where('key', 'service_material_auto_add_mode')->value('value') ?? 'confirm',
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
            'warehouse_enabled' => ['boolean'],
            'service_material_auto_add_mode' => ['required', 'string', 'in:off,confirm,silent'],
        ]);

        // Живой баг, найденный на реальном тенанте: режим включали без проверки,
        // что для него вообще есть подходящие склады — 'per_branch' с единственным
        // company-складом резолвился в null, и завершение заказа падало с
        // исключением в самый неподходящий момент. Теперь несогласованное
        // сохранение блокируется здесь же, а не только предупреждается постфактум.
        if ($validated['warehouse_enabled'] ?? true) {
            if ($error = $this->modeCoverageError($validated['warehouse_mode'])) {
                return redirect()->back()->withErrors(['warehouse_mode' => $error]);
            }
        }

        Setting::updateOrCreate(
            ['key' => 'warehouse_mode'],
            ['value' => $validated['warehouse_mode']]
        );

        Setting::updateOrCreate(
            ['key' => 'warehouse_enabled'],
            ['value' => ($validated['warehouse_enabled'] ?? true) ? '1' : '0']
        );

        Setting::updateOrCreate(
            ['key' => 'service_material_auto_add_mode'],
            ['value' => $validated['service_material_auto_add_mode']]
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

        // Деактивация (не удаление) — если это последний активный склад, реально
        // покрывающий свою область (локацию/компанию) в ТЕКУЩЕМ режиме, блокируем
        // ровно тем же принципом, что и store() выше: не даём создать несогласованную
        // конфигурацию, откуда её ни коснись. Смена owner_type/owner_id при этом
        // намеренно НЕ проверяется — это редкое, осознанное "переносим склад в другое
        // место" действие, не «тихая потеря покрытия», разбирать его отдельно не стали.
        if ($warehouse->is_active && ! ($validated['is_active'] ?? true)) {
            if ($error = $this->soleCoverageError($warehouse)) {
                return redirect()->back()->withErrors(['error' => $error]);
            }
        }

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

        if ($warehouse->is_active && ($error = $this->soleCoverageError($warehouse))) {
            return redirect()->back()->withErrors(['error' => $error]);
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

    /**
     * Проверяет, что для режима $mode физически есть чем резолвить списание —
     * общий источник истины для store() (блокирует НОВОЕ несогласованное
     * сохранение) и index() (диагностика уже сохранённой конфигурации).
     * $excludeWarehouseId прокидывается насквозь в WarehouseResolver — им
     * пользуются updateWarehouse()/destroyWarehouse() через soleCoverageError()
     * ниже, чтобы проверить состояние БЕЗ конкретного склада, ДО того как
     * деактивация/удаление реально применены.
     */
    private function modeCoverageError(string $mode, ?int $excludeWarehouseId = null): ?string
    {
        if ($mode === 'shared') {
            return WarehouseResolver::hasActiveCompanyWarehouse($excludeWarehouseId)
                ? null
                : 'Нет ни одного активного общего склада — в режиме «Общий» списание не будет работать ни для одной локации. Создайте склад с типом «Общий (компания)».';
        }

        if (in_array($mode, ['per_branch', 'mixed'], true)) {
            $missing = WarehouseResolver::branchesWithoutWarehouse($excludeWarehouseId);
            if ($missing->isEmpty()) {
                return null;
            }

            $modeLabel = $mode === 'per_branch' ? 'раздельном' : 'смешанном';
            $names = $missing->pluck('name')->implode('», «');

            return "В {$modeLabel} режиме списание не будет работать для локаций без своего склада: «{$names}». Создайте склад для каждой из них (или для отсутствующей — см. список) перед сохранением.";
        }

        return null;
    }

    /**
     * Узкая проверка для деактивации/удаления ОДНОГО конкретного склада — в
     * отличие от modeCoverageError() (сканирует ВСЕ локации сразу и годится
     * для баннера/смены режима), эта сообщает только о ТОЙ области (локация
     * или компания), которую реально затрагивает именно этот склад — чтобы
     * не путать пользователя чужой, никак не связанной с его действием
     * проблемой у другой локации, если та уже была без склада раньше.
     */
    private function soleCoverageError(Warehouse $warehouse): ?string
    {
        $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';

        if ($mode === 'shared' && $warehouse->owner_type === 'company') {
            return WarehouseResolver::hasActiveCompanyWarehouse($warehouse->id)
                ? null
                : 'Это последний активный общий склад, а выбран режим склада «Общий» — без него списание перестанет работать для всех локаций. Сначала добавьте другой общий склад или смените режим склада.';
        }

        if (in_array($mode, ['per_branch', 'mixed'], true) && $warehouse->owner_type === 'branch' && $warehouse->owner_id) {
            $branch = Branch::find($warehouse->owner_id);
            if (! $branch) {
                return null;
            }

            $stillMissing = WarehouseResolver::branchesWithoutWarehouse($warehouse->id)->contains('id', $branch->id);

            return $stillMissing
                ? "Это последний активный склад локации «{$branch->name}», а выбран раздельный/смешанный режим склада — без него списание для этой локации перестанет работать. Сначала добавьте ей другой склад или смените режим склада."
                : null;
        }

        return null;
    }
}

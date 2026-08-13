<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Lookup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\QueryFilterService;
use App\Services\StockService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Приходные накладные (Фаза "Поставщики" — см. CLAUDE.md). Заменяет прежнее
 * прямое оприходование через StockMovementController::storeReceipt() —
 * теперь позиции объединены под накладной с поставщиком, а не разрозненные
 * StockMovement без общего контекста. Список и Карточка — Tri-State Record
 * Pattern (см. CLAUDE.md), эталон — WorkOrderController.
 */
class GoodsReceiptController extends Controller
{
    /**
     * Значение системной роли клиента (см. WorkOrderController::CONTRACTOR_ROLE
     * — та же логика: стабильный value-слаг, а не отображаемый текст, поэтому
     * переименование роли в Справочниках это сравнение не ломает).
     */
    private const SUPPLIER_ROLE = 'supplier';

    private const SUPPLIER_ROLE_LABEL = 'Поставщик';

    public function index(Request $request): Response
    {
        $query = GoodsReceipt::with(['supplier:id,name,phone', 'warehouse:id,name', 'branch:id,name'])
            ->withCount('items');

        $query = QueryFilterService::apply($query, $request->all(), ['supplier_document_number', 'supplier.name']);

        if (! $request->has('sort_by')) {
            $query->orderBy('receipt_date', 'desc')->orderBy('id', 'desc');
        }

        $receipts = $query->paginate(15)->withQueryString();

        return Inertia::render('Warehouse/GoodsReceipts/Index', [
            'receipts' => $receipts,
            'filters' => $request->all(),
            'suppliers' => $this->supplierOptions(),
            // Для формы быстрого добавления поставщика (crm.clients.store,
            // тот же приём, что и "+ клиент" в WorkOrders/Index.vue) — новому
            // клиенту сразу нужна роль «Поставщик», иначе он не попадёт в
            // supplierOptions() и его придётся донастраивать отдельно.
            'supplierRoleId' => Lookup::where('type', 'client_role')->where('value', self::SUPPLIER_ROLE)->value('id'),
            // Для CompanySuggestInput в модалке быстрого добавления поставщика —
            // DaData знает только российские организации (см. CRM/Clients,
            // Settings/LegalEntities — тот же принцип).
            'tenantCountry' => config('tenant.country_code', 'RU'),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name']),
            'branches' => Branch::forSelect()->with('legalEntities:id,name')->get(['id', 'name']),
            'products' => Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'accounting_type']),
            // Для модалки быстрого добавления товара (см. CompanySuggestInput-
            // аналог для товаров — переиспользуем существующий
            // WorkOrderController::storeProductQuick(), отдельный эндпоинт не нужен).
            'productCategories' => ProductCategory::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateReceipt($request);

        try {
            $receipt = StockService::receiveGoods($validated, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оприходовании: '.$e->getMessage()]);
        }

        $receipt->load('warehouse:id,name');
        ActivityLogger::log($receipt, "Приходная накладная №{$receipt->id} создана — товар оприходован на склад «{$receipt->warehouse->name}»", [], 'created');

        return redirect()->route('warehouse.goods-receipts.show', $receipt->id)->with('success', 'Товары оприходованы, накладная создана');
    }

    public function show(GoodsReceipt $receipt): Response
    {
        $receipt->load([
            'supplier',
            'warehouse',
            'branch' => fn ($q) => $q->withTrashed(),
            'legalEntity' => fn ($q) => $q->withTrashed(),
            'items.product',
            'items.batch',
        ])->append('total_value');

        $presented = ActivityLogger::present(ActivityLogger::feedFor($receipt));

        return Inertia::render('Warehouse/GoodsReceipts/Show', [
            'receipt' => $receipt,
            'activities' => $presented['activities'],
            // Для добавления/редактирования позиций и quick-add товара прямо
            // с Карточки — тот же набор, что и на списке (index()).
            'products' => Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'accounting_type']),
            'productCategories' => ProductCategory::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    /**
     * Добавление позиции к уже существующей накладной — StockService::
     * addReceiptItem() (блокируется для отменённой накладной).
     */
    public function addItem(Request $request, GoodsReceipt $receipt)
    {
        $itemData = $this->validateItem($request);

        try {
            StockService::addReceiptItem($receipt, $itemData, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "В накладную №{$receipt->id} добавлена позиция", [], 'item_added');

        return redirect()->back()->with('success', 'Позиция добавлена');
    }

    /**
     * Правка количества/цены/партии/товара уже существующей позиции —
     * StockService::updateReceiptItem() (реверс старого движения + повторное
     * оприходование новыми значениями; блокируется, если товар с этой
     * позиции уже частично списан — та же защита, что и у отмены накладной).
     */
    public function updateItem(Request $request, GoodsReceipt $receipt, GoodsReceiptItem $item)
    {
        if ($item->goods_receipt_id !== $receipt->id) {
            abort(403);
        }

        $itemData = $this->validateItem($request);

        try {
            StockService::updateReceiptItem($item, $itemData, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "В накладной №{$receipt->id} изменена позиция", [], 'item_updated');

        return redirect()->back()->with('success', 'Позиция обновлена');
    }

    /**
     * Удаление одной позиции — StockService::removeReceiptItem() (реверс
     * движения/остатка/партии, сама строка удаляется; блокируется, если
     * товар с этой позиции уже частично списан).
     */
    public function destroyItem(GoodsReceipt $receipt, GoodsReceiptItem $item)
    {
        if ($item->goods_receipt_id !== $receipt->id) {
            abort(403);
        }

        try {
            StockService::removeReceiptItem($item, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "Из накладной №{$receipt->id} удалена позиция", [], 'item_removed');

        return redirect()->back()->with('success', 'Позиция удалена');
    }

    /**
     * Отмена — реверс движений/остатков/партий (StockService::reverseReceipt()),
     * блокируется, если товар с поставки уже частично списан. Не удаление
     * записи: накладная остаётся в списке со статусом "Отменена".
     */
    public function cancel(GoodsReceipt $receipt)
    {
        try {
            StockService::reverseReceipt($receipt, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "Приход по накладной №{$receipt->id} отменён — движения реверсированы", [], 'canceled');

        return redirect()->back()->with('success', 'Накладная отменена');
    }

    private function supplierOptions()
    {
        return Client::whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::SUPPLIER_ROLE))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);
    }

    /**
     * Юрлицо накладной обязано реально входить в legalEntities() выбранной
     * локации — тот же паттерн, что и у WorkOrderController::
     * legalEntityBelongsToBranchRule() (защита от подмены запроса напрямую,
     * фронт и так фильтрует список).
     */
    private function legalEntityBelongsToBranchRule(Request $request): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($request) {
            if (! $value) {
                return;
            }

            $branch = Branch::find($request->input('branch_id'));

            if ($branch && ! $branch->legalEntities()->where('legal_entities.id', $value)->exists()) {
                $fail('Выбранное юрлицо не привязано к этой локации.');
            }
        };
    }

    /**
     * @return array{product_id:int, quantity:float, cost_price:int, batch_number:?string}
     */
    private function validateItem(Request $request): array
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:255'],
        ]);

        // cost_price приходит с фронта в рублях — переводим в копейки, тот
        // же приём, что и у items.*.cost_price в validateReceipt().
        $validated['cost_price'] = (int) round($validated['cost_price'] * 100);

        return $validated;
    }

    private function validateReceipt(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:clients,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id', $this->legalEntityBelongsToBranchRule($request)],
            'receipt_date' => ['required', 'date'],
            'supplier_document_number' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:255'],
        ]);

        $isSupplier = Client::where('id', $validated['supplier_id'])
            ->whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::SUPPLIER_ROLE))
            ->exists();

        if (! $isSupplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Поставщиком можно указать только клиента с ролью «'.self::SUPPLIER_ROLE_LABEL.'».',
            ]);
        }

        // cost_price приходит с фронта в рублях (как и раньше в
        // storeReceipt()) — переводим в копейки перед передачей в StockService.
        $validated['items'] = array_map(function ($item) {
            $item['cost_price'] = (int) round($item['cost_price'] * 100);

            return $item;
        }, $validated['items']);

        return $validated;
    }
}

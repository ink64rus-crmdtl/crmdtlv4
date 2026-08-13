<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Branch;
use App\Models\ListView;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Журнал складских движений — read-only лента (оприходование теперь идёт
 * через GoodsReceiptController, накладная с поставщиком; списания создаёт
 * StockService::deduct() при завершении заказа). Раньше здесь же было и
 * оприходование (storeReceipt()) — убрано, см. warehouse.goods-receipts.*.
 */
class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $query = StockMovement::with(['warehouse', 'branch', 'product.category', 'batch', 'workOrder', 'goodsReceipt:id,supplier_id', 'goodsReceipt.supplier:id,name']);

        // Кастомный поиск по названию товара или номеру заказа
        if ($request->filled('search')) {
            $searchTerm = '%'.$request->search.'%';
            $query->where(function ($q) use ($searchTerm) {
                $q->whereHas('product', function ($pq) use ($searchTerm) {
                    $pq->where('name', 'LIKE', $searchTerm)
                        ->orWhere('sku', 'LIKE', $searchTerm);
                })->orWhere('work_order_id', 'LIKE', $searchTerm);
            });
        }

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            [] // Глобальный поиск обработан выше
        );

        if (! $request->has('sort_by')) {
            $query->orderBy('created_at', 'desc');
        }

        $movements = $query->paginate(15)->withQueryString();

        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name']);
        $branches = Branch::forSelect()->get(['id', 'name']);

        $availableColumns = [
            ['key' => 'date', 'label' => 'Дата'],
            ['key' => 'type', 'label' => 'Тип'],
            ['key' => 'warehouse_branch', 'label' => 'Склад / Локация'],
            ['key' => 'product', 'label' => 'Товар'],
            ['key' => 'quantity', 'label' => 'Кол-во'],
            ['key' => 'cost_price', 'label' => 'Себестоимость'],
            ['key' => 'reason', 'label' => 'Основание'],
        ];

        $listView = ListView::where('entity_type', 'stock_movement')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Warehouse/Movements/Index', [
            'movements' => $movements,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'filters' => $request->all(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:stock_movements,id'],
        ]);

        ExportEntitiesJob::dispatch('stock_movements', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}

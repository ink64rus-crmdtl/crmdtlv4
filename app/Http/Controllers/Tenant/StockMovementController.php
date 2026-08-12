<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ListView;
use App\Services\StockService;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $query = StockMovement::with(['warehouse', 'branch', 'product.category', 'batch', 'workOrder']);

        // Кастомный поиск по названию товара или номеру заказа
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
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

        if (!$request->has('sort_by')) {
            $query->orderBy('created_at', 'desc');
        }

        $movements = $query->paginate(15)->withQueryString();
        
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name']);
        $branches = Branch::forSelect()->get(['id', 'name']);
        $products = Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'accounting_type']);

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
            'products' => $products,
            'filters' => $request->all(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function storeReceipt(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $warehouse = Warehouse::find($validated['warehouse_id']);
                
                foreach ($validated['items'] as $item) {
                    $product = Product::find($item['product_id']);
                    $costPriceCents = (int) round($item['cost_price'] * 100);

                    StockService::receipt(
                        $product,
                        $warehouse,
                        $validated['branch_id'],
                        $item['quantity'],
                        $costPriceCents,
                        auth()->id()
                    );
                }
            });

            return redirect()->back()->with('success', 'Товары успешно оприходованы на склад');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оприходовании: ' . $e->getMessage()]);
        }
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
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\ProductCategory;
use App\Models\ListView;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = StockBalance::with(['warehouse', 'product.category']);

        // Кастомный поиск по названию товара или артикулу
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->whereHas('product', function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', $searchTerm)
                  ->orWhere('sku', 'LIKE', $searchTerm);
            });
        }

        // Фильтрация по складу
        if ($request->filled('filters.warehouse_id')) {
            $query->where('warehouse_id', $request->input('filters.warehouse_id'));
        }

        // Фильтрация по категории товара
        if ($request->filled('filters.product_category_id')) {
            $categoryId = $request->input('filters.product_category_id');
            $query->whereHas('product', function ($q) use ($categoryId) {
                $q->where('product_category_id', $categoryId);
            });
        }

        // Скрывать нулевые остатки по умолчанию
        if ($request->input('filters.hide_empty', '1') === '1') {
            $query->where('quantity', '>', 0);
        }

        if (!$request->has('sort_by')) {
            $query->orderBy('quantity', 'desc');
        } else {
            $direction = $request->input('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($request->input('sort_by'), $direction);
        }

        $balances = $query->paginate(15)->withQueryString();
        
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name']);
        $categories = ProductCategory::where('is_active', true)->get(['id', 'name']);

        $availableColumns = [
            ['key' => 'warehouse', 'label' => 'Склад'],
            ['key' => 'category', 'label' => 'Категория'],
            ['key' => 'product', 'label' => 'Товар / Артикул'],
            ['key' => 'quantity', 'label' => 'Остаток'],
            ['key' => 'avg_cost', 'label' => 'Ср. Себестоимость'],
            ['key' => 'total_value', 'label' => 'Общая стоимость'],
        ];

        $listView = ListView::where('entity_type', 'stock_balance')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Warehouse/Balances/Index', [
            'balances' => $balances,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'filters' => $request->all(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:stock_balances,id'],
        ]);

        ExportEntitiesJob::dispatch('stock_balances', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}
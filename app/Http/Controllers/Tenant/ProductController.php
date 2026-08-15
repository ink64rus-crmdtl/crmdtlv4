<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\ListView;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Services\QueryFilterService;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Product::with(['category', 'preferredWarehouse']);

        // Справочная средняя себестоимость (для подсказки наценки в форме) —
        // только если склад вообще ведётся, иначе StockBalance пуст по смыслу.
        if (WarehouseResolver::isEnabled()) {
            $query->with('stockBalances:id,product_id,quantity,avg_cost');
        }

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['name', 'sku'],
            allowedSorts: ['sku', 'unit', 'accounting_type', 'is_active', 'base_price']
        );

        if (! $request->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        // Если AJAX-запрос для SearchableSelect, возвращаем только пагинированные данные (Исключаем Inertia)
        if (($request->wantsJson() || $request->ajax()) && ! $request->hasHeader('X-Inertia')) {
            return response()->json($query->paginate(15));
        }

        $products = $query->paginate(15)->withQueryString();
        $categories = ProductCategory::orderBy('id')->get();
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name']);

        $availableColumns = [
            ['key' => 'category', 'label' => 'Категория'],
            ['key' => 'sku', 'label' => 'Артикул'],
            ['key' => 'name', 'label' => 'Название'],
            ['key' => 'unit', 'label' => 'Ед. изм.'],
            ['key' => 'accounting_type', 'label' => 'Тип учета'],
            ['key' => 'price', 'label' => 'Цена'],
            ['key' => 'status', 'label' => 'Статус'],
        ];

        $listView = ListView::where('entity_type', 'product')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Warehouse/Products/Index', [
            'products' => $products,
            'categories' => $categories,
            'warehouses' => $warehouses,
            'warehouseEnabled' => WarehouseResolver::isEnabled(),
            'filters' => $request->all(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'accounting_type' => ['required', 'string', 'in:average,batch'],
            'preferred_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'is_active' => ['boolean'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        Product::create([
            'product_category_id' => $validated['product_category_id'],
            'name' => [app()->getLocale() => $validated['name']],
            'sku' => $validated['sku'],
            'unit' => $validated['unit'],
            'accounting_type' => $validated['accounting_type'],
            'preferred_warehouse_id' => $validated['preferred_warehouse_id'],
            'is_active' => $validated['is_active'] ?? true,
            'base_price' => isset($validated['base_price']) ? (int) round($validated['base_price'] * 100) : null,
            'markup_percent' => $validated['markup_percent'] ?? null,
            'discount_percent' => $validated['discount_percent'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Товар успешно добавлен');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'accounting_type' => ['required', 'string', 'in:average,batch'],
            'preferred_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'is_active' => ['boolean'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $name = $product->getTranslations('name');
        $name[app()->getLocale()] = $validated['name'];

        $product->update([
            'product_category_id' => $validated['product_category_id'],
            'name' => $name,
            'sku' => $validated['sku'],
            'unit' => $validated['unit'],
            'accounting_type' => $validated['accounting_type'],
            'preferred_warehouse_id' => $validated['preferred_warehouse_id'],
            'is_active' => $validated['is_active'] ?? true,
            'base_price' => isset($validated['base_price']) ? (int) round($validated['base_price'] * 100) : null,
            'markup_percent' => $validated['markup_percent'] ?? null,
            'discount_percent' => $validated['discount_percent'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Товар обновлен');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->back()->with('success', 'Товар удален');
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        ProductCategory::create([
            'name' => [app()->getLocale() => $validated['name']],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Категория добавлена');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:products,id'],
        ]);

        Product::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные товары удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:products,id'],
        ]);

        ExportEntitiesJob::dispatch('products', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен.');
    }
}

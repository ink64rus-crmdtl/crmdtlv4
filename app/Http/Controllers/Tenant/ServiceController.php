<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\BusinessDirection;
use App\Models\Lookup;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceDefaultMaterial;
use App\Models\Setting;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        // defaultMaterials — правило автодобавления материала (CLAUDE.md
        // «Материалы на услугу»), нужно фронту для CRUD прямо на карточке услуги.
        $query = Service::with(['category', 'businessDirection', 'defaultMaterials.product:id,name,unit']);

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['name'],
            allowedSorts: ['price', 'duration_minutes', 'is_active']
        );

        if (! $request->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        // Если AJAX-запрос для SearchableSelect, возвращаем только пагинированные данные (Исключаем Inertia)
        if (($request->wantsJson() || $request->ajax()) && ! $request->hasHeader('X-Inertia')) {
            return response()->json($query->paginate(15));
        }

        $services = $query->paginate(15)->withQueryString();
        $categories = ServiceCategory::orderBy('id')->get();
        $businessDirections = BusinessDirection::where('is_active', true)->get(['id', 'name']);

        $pricingBasis = Setting::where('key', 'pricing_basis')->value('value') ?? 'none';
        $lookups = Lookup::whereIn('type', ['vehicle_body', 'vehicle_class'])->where('is_active', true)->get()->groupBy('type');

        return Inertia::render('Operations/Services/Index', [
            'services' => $services,
            'categories' => $categories,
            'businessDirections' => $businessDirections,
            'pricingBasis' => $pricingBasis,
            'lookups' => $lookups,
            'filters' => $request->all(),
            'materialProducts' => Product::where('is_active', true)->get(['id', 'name', 'unit']),
        ]);
    }

    /**
     * Материал по умолчанию для услуги (ServiceDefaultMaterial, CLAUDE.md
     * «Материалы на услугу») — правило автодобавления при добавлении услуги
     * в заказ, не сама позиция заказа.
     */
    public function storeDefaultMaterial(Request $request, Service $service)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $existing = ServiceDefaultMaterial::where('service_id', $service->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existing) {
            return redirect()->back()->withErrors(['product_id' => 'Этот материал уже добавлен в правило — измените количество у существующей строки.']);
        }

        ServiceDefaultMaterial::create([
            'service_id' => $service->id,
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
        ]);

        return redirect()->back()->with('success', 'Материал добавлен в правило автодобавления');
    }

    public function updateDefaultMaterial(Request $request, ServiceDefaultMaterial $material)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $material->update($validated);

        return redirect()->back()->with('success', 'Количество обновлено');
    }

    public function destroyDefaultMaterial(ServiceDefaultMaterial $material)
    {
        $material->delete();

        return redirect()->back()->with('success', 'Материал убран из правила автодобавления');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_category_id' => ['nullable', 'exists:service_categories,id'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        Service::create([
            'service_category_id' => $validated['service_category_id'] ?? null,
            'business_direction_id' => $validated['business_direction_id'] ?? null,
            'name' => [app()->getLocale() => $validated['name']],
            'price' => (int) round($validated['price'] * 100),
            'prices' => $validated['prices'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Услуга успешно добавлена');
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'service_category_id' => ['nullable', 'exists:service_categories,id'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $name = $service->getTranslations('name');
        $name[app()->getLocale()] = $validated['name'];

        $service->update([
            'service_category_id' => $validated['service_category_id'] ?? null,
            'business_direction_id' => $validated['business_direction_id'] ?? null,
            'name' => $name,
            'price' => (int) round($validated['price'] * 100),
            'prices' => $validated['prices'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Услуга обновлена');
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return redirect()->back()->with('success', 'Услуга удалена');
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        ServiceCategory::create([
            'name' => [app()->getLocale() => $validated['name']],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Категория добавлена');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:services,id'],
        ]);

        Service::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные услуги удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:services,id'],
        ]);

        ExportEntitiesJob::dispatch('services', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен.');
    }
}

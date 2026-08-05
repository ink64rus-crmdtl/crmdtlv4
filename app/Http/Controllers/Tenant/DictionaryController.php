<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Lookup;
use App\Models\ServiceCategory;
use App\Models\ProductCategory;
use App\Models\BusinessDirection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DictionaryController extends Controller
{
    public function index(): Response
    {
        $makes = VehicleMake::with('models')->orderBy('name')->get();
        $lookups = Lookup::orderBy('value')->get()->groupBy('type');
        $serviceCategories = ServiceCategory::with('businessDirection')->orderBy('id')->get();
        $productCategories = ProductCategory::orderBy('id')->get();
        $businessDirections = BusinessDirection::where('is_active', true)->get(['id', 'name']);

        return Inertia::render('Settings/Dictionaries/Index', [
            'makes' => $makes,
            'lookups' => $lookups,
            'serviceCategories' => $serviceCategories,
            'productCategories' => $productCategories,
            'businessDirections' => $businessDirections,
        ]);
    }

    public function storeMake(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:vehicle_makes,name'],
            'is_active' => ['boolean'],
        ]);

        VehicleMake::create($validated);

        return redirect()->back()->with('success', 'Марка добавлена');
    }

    public function updateMake(Request $request, VehicleMake $make)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:vehicle_makes,name,' . $make->id],
            'is_active' => ['boolean'],
        ]);

        $make->update($validated);

        return redirect()->back()->with('success', 'Марка обновлена');
    }

    public function destroyMake(VehicleMake $make)
    {
        $make->delete();
        return redirect()->back()->with('success', 'Марка удалена');
    }

    public function storeModel(Request $request)
    {
        $validated = $request->validate([
            'vehicle_make_id' => ['required', 'exists:vehicle_makes,id'],
            'name' => ['required', 'string', 'max:255'],
            'body_type' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        VehicleModel::create($validated);

        return redirect()->back()->with('success', 'Модель добавлена');
    }

    public function updateModel(Request $request, VehicleModel $model)
    {
        $validated = $request->validate([
            'vehicle_make_id' => ['required', 'exists:vehicle_makes,id'],
            'name' => ['required', 'string', 'max:255'],
            'body_type' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $model->update($validated);

        return redirect()->back()->with('success', 'Модель обновлена');
    }

    public function destroyModel(VehicleModel $model)
    {
        $model->delete();
        return redirect()->back()->with('success', 'Модель удалена');
    }

    public function storeServiceCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
        ]);

        ServiceCategory::create([
            'name' => [app()->getLocale() => $validated['name']],
            'business_direction_id' => $validated['business_direction_id'],
            'is_active' => true
        ]);

        return redirect()->back()->with('success', 'Категория услуг добавлена');
    }

    public function updateServiceCategory(Request $request, ServiceCategory $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
        ]);

        $name = $category->name;
        $name[app()->getLocale()] = $validated['name'];

        $category->update([
            'name' => $name,
            'business_direction_id' => $validated['business_direction_id']
        ]);

        return redirect()->back()->with('success', 'Категория услуг обновлена');
    }

    public function destroyServiceCategory(ServiceCategory $category)
    {
        $category->delete();
        return redirect()->back()->with('success', 'Категория услуг удалена');
    }

    public function storeProductCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255']
        ]);

        ProductCategory::create([
            'name' => [app()->getLocale() => $validated['name']],
            'is_active' => true
        ]);

        return redirect()->back()->with('success', 'Категория товаров добавлена');
    }

    public function updateProductCategory(Request $request, ProductCategory $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255']
        ]);

        $name = $category->name;
        $name[app()->getLocale()] = $validated['name'];

        $category->update([
            'name' => $name
        ]);

        return redirect()->back()->with('success', 'Категория товаров обновлена');
    }

    public function destroyProductCategory(ProductCategory $category)
    {
        $category->delete();
        return redirect()->back()->with('success', 'Категория товаров удалена');
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $path = $request->file('file')->getRealPath();
        $data = array_map(function($v) { return str_getcsv($v, ";"); }, file($path));

        $imported = 0;

        foreach ($data as $index => $row) {
            // Пропускаем заголовок, если он есть
            if ($index === 0 && strtolower(trim($row[0])) === 'марка') continue;
            if (count($row) < 2) continue;

            $makeName = trim($row[0]);
            $modelName = trim($row[1]);
            $bodyType = isset($row[2]) ? trim($row[2]) : null;
            $category = isset($row[3]) ? trim($row[3]) : null;

            if (!$makeName || !$modelName) continue;

            $make = VehicleMake::firstOrCreate(['name' => $makeName]);
            
            VehicleModel::firstOrCreate([
                'vehicle_make_id' => $make->id,
                'name' => $modelName,
            ], [
                'body_type' => $bodyType,
                'category' => $category,
                'is_active' => true
            ]);

            $imported++;
        }

        return redirect()->back()->with('success', "Справочник успешно импортирован. Обработано записей: {$imported}");
    }
}
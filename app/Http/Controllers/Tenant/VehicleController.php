<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Client;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\ListView;
use App\Models\Setting;
use App\Services\FieldPermissionService;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        
        // Получаем автомобили, владельцы которых доступны в текущем филиале (BranchScope на клиенте)
        $query = Vehicle::whereHas('client')->with(['client', 'make', 'vehicleModel']);
        
        // Применяем серверную фильтрацию и поиск
        $query = QueryFilterService::apply(
            $query, 
            request()->all(), 
            ['plate_number', 'vin'], 
            'vehicle'
        );

        // Сортировка по умолчанию, если не задана иная
        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        // Пагинация вместо ->get()
        $vehicles = $query->paginate(15)->withQueryString();
        
        // Список клиентов для выпадающего списка при создании авто
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone', 'alias']);
        
        // Справочники марок и моделей
        $makes = VehicleMake::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $models = VehicleModel::where('is_active', true)->orderBy('name')->get(['id', 'vehicle_make_id', 'name', 'body_type', 'category']);

        // Настройка строгой валидации
        $strictPlateValidation = Setting::where('key', 'strict_plate_validation')->value('value') === '1';
        $tenantCountry = config('tenant.country_code', 'RU');

        // --- ДИНАМИЧЕСКИЕ ТАБЛИЦЫ И КАСТОМНЫЕ ПОЛЯ ---
        
        // 1. Формируем базовый список системных колонок
        $baseColumns = [
            ['key' => 'vehicle_info', 'label' => 'Автомобиль', 'type' => 'system', 'is_default' => true],
            ['key' => 'client', 'label' => 'Владелец', 'type' => 'system', 'is_default' => true],
            ['key' => 'plate_number', 'label' => 'Госномер', 'type' => 'system', 'is_default' => true],
            ['key' => 'vin', 'label' => 'VIN', 'type' => 'system', 'is_default' => false],
            ['key' => 'year', 'label' => 'Год выпуска', 'type' => 'system', 'is_default' => false],
        ];

        // 2. Подмешиваем кастомные поля для Автомобилей
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'vehicle')->orderBy('sort_order')->get();
        foreach ($customFieldDefs as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        // 3. Загружаем значения кастомных полей только для авто на текущей странице
        $cfValues = CustomFieldValue::where('entity_type', 'vehicle')
            ->whereIn('entity_id', $vehicles->getCollection()->pluck('id'))
            ->get();

        // 4. Мапим значения кастомных полей внутрь объектов авто для удобства Vue
        $vehicles->getCollection()->transform(function ($vehicle) use ($cfValues, $customFieldDefs) {
            $vehicleData = $vehicle->toArray();
            $vehicleData['custom_fields'] = [];
            
            foreach ($customFieldDefs as $def) {
                $val = $cfValues->where('entity_id', $vehicle->id)->where('custom_field_definition_id', $def->id)->first();
                $vehicleData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }
            
            return $vehicleData;
        });

        // 5. Фильтруем колонки через FieldPermissionService
        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'vehicle', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        // 6. Получаем сохраненный вид таблицы для текущего пользователя
        $listView = ListView::where('entity_type', 'vehicle')
            ->where('user_id', $user->id)
            ->first();

        $visibleColumns = $listView 
            ? $listView->visible_columns 
            : array_values(array_map(fn($c) => $c['key'], array_filter($availableColumns, fn($c) => $c['is_default'])));

        return Inertia::render('CRM/Vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => request()->all(),
            'clients' => $clients,
            'makes' => $makes,
            'models' => $models,
            'strictPlateValidation' => $strictPlateValidation,
            'tenantCountry' => $tenantCountry,
            'customFieldDefs' => $customFieldDefs,
            'availableColumns' => $availableColumns,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
        ]);
    }

    public function show(Vehicle $vehicle): Response
    {
        $vehicle->load(['client', 'make', 'vehicleModel']);
        
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'vehicle')->orderBy('sort_order')->get();
        $cfValues = CustomFieldValue::where('entity_type', 'vehicle')->where('entity_id', $vehicle->id)->get();
        
        $customFieldsData = [];
        foreach ($customFieldDefs as $def) {
            $val = $cfValues->where('custom_field_definition_id', $def->id)->first();
            $customFieldsData[] = [
                'definition' => $def,
                'value' => $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null,
            ];
        }

        // Данные для модального окна редактирования
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone', 'alias']);
        $makes = VehicleMake::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $models = VehicleModel::where('is_active', true)->orderBy('name')->get(['id', 'vehicle_make_id', 'name', 'body_type', 'category']);
        $strictPlateValidation = Setting::where('key', 'strict_plate_validation')->value('value') === '1';
        $tenantCountry = config('tenant.country_code', 'RU');

        return Inertia::render('CRM/Vehicles/Show', [
            'vehicle' => $vehicle,
            'customFieldsData' => $customFieldsData,
            'clients' => $clients,
            'makes' => $makes,
            'models' => $models,
            'strictPlateValidation' => $strictPlateValidation,
            'tenantCountry' => $tenantCountry,
            'customFieldDefs' => $customFieldDefs,
        ]);
    }

    public function store(Request $request)
    {
        $strictValidation = Setting::where('key', 'strict_plate_validation')->value('value') === '1';
        $plateRules = ['nullable', 'string', 'max:255'];

        if ($strictValidation && $request->filled('plate_number')) {
            $country = config('tenant.country_code', 'RU');
            if ($country === 'RU') {
                // Разрешаем кириллицу и латиницу, маска А 000 АА 00(0)
                $plateRules[] = 'regex:/^[АВЕКМНОРСТУХABEKMHOPCTYXавекмнорстух]\s?\d{3}\s?[АВЕКМНОРСТУХABEKMHOPCTYXавекмнорстух]{2}\s?\d{2,3}$/u';
            } elseif ($country === 'BY') {
                $plateRules[] = 'regex:/^\d{4}\s?[A-Za-z]{2}-[1-7]$/u';
            } elseif ($country === 'KZ') {
                $plateRules[] = 'regex:/^\d{3}\s?[A-Za-z]{2,3}\s?\d{2}$/u';
            }
        }

        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_make_id' => ['required', 'exists:vehicle_makes,id'],
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'plate_number' => $plateRules,
            'vin' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'custom_fields' => ['nullable', 'array'],
        ], [
            'plate_number.regex' => 'Госномер не соответствует формату выбранной страны.',
        ]);

        DB::transaction(function () use ($validated) {
            $vehicle = Vehicle::create([
                'client_id' => $validated['client_id'],
                'vehicle_make_id' => $validated['vehicle_make_id'],
                'vehicle_model_id' => $validated['vehicle_model_id'],
                'plate_number' => $validated['plate_number'] ? mb_strtoupper(str_replace(' ', '', $validated['plate_number'])) : null,
                'vin' => $validated['vin'] ? mb_strtoupper($validated['vin']) : null,
                'year' => $validated['year'] ?? null,
            ]);

            if (!empty($validated['custom_fields'])) {
                $this->saveCustomFields($vehicle, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Автомобиль успешно добавлен');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $strictValidation = Setting::where('key', 'strict_plate_validation')->value('value') === '1';
        $plateRules = ['nullable', 'string', 'max:255'];

        if ($strictValidation && $request->filled('plate_number')) {
            $country = config('tenant.country_code', 'RU');
            if ($country === 'RU') {
                $plateRules[] = 'regex:/^[АВЕКМНОРСТУХABEKMHOPCTYXавекмнорстух]\s?\d{3}\s?[АВЕКМНОРСТУХABEKMHOPCTYXавекмнорстух]{2}\s?\d{2,3}$/u';
            } elseif ($country === 'BY') {
                $plateRules[] = 'regex:/^\d{4}\s?[A-Za-z]{2}-[1-7]$/u';
            } elseif ($country === 'KZ') {
                $plateRules[] = 'regex:/^\d{3}\s?[A-Za-z]{2,3}\s?\d{2}$/u';
            }
        }

        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_make_id' => ['required', 'exists:vehicle_makes,id'],
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'plate_number' => $plateRules,
            'vin' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'custom_fields' => ['nullable', 'array'],
        ], [
            'plate_number.regex' => 'Госномер не соответствует формату выбранной страны.',
        ]);

        DB::transaction(function () use ($validated, $vehicle) {
            $vehicle->update([
                'client_id' => $validated['client_id'],
                'vehicle_make_id' => $validated['vehicle_make_id'],
                'vehicle_model_id' => $validated['vehicle_model_id'],
                'plate_number' => $validated['plate_number'] ? mb_strtoupper(str_replace(' ', '', $validated['plate_number'])) : null,
                'vin' => $validated['vin'] ? mb_strtoupper($validated['vin']) : null,
                'year' => $validated['year'] ?? null,
            ]);

            if (isset($validated['custom_fields'])) {
                $this->saveCustomFields($vehicle, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Данные автомобиля обновлены');
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
        return redirect()->back()->with('success', 'Автомобиль удален');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:vehicles,id'],
        ]);

        Vehicle::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные автомобили удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:vehicles,id'],
        ]);

        $vehicles = Vehicle::with(['client', 'make', 'vehicleModel'])->whereIn('id', $validated['ids'])->get();
        
        $filename = 'vehicles_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];
        
        $callback = function() use($vehicles) {
            $file = fopen('php://output', 'w');
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            fputcsv($file, ['ID', 'Марка', 'Модель', 'Госномер', 'VIN', 'Год', 'Владелец', 'Телефон владельца'], ';');
            
            foreach ($vehicles as $vehicle) {
                fputcsv($file, [
                    $vehicle->id,
                    $vehicle->make ? $vehicle->make->name : '',
                    $vehicle->vehicleModel ? $vehicle->vehicleModel->name : '',
                    $vehicle->plate_number,
                    $vehicle->vin,
                    $vehicle->year,
                    $vehicle->client ? $vehicle->client->name : 'Неизвестно',
                    $vehicle->client ? $vehicle->client->phone : ''
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function saveCustomFields(Vehicle $vehicle, array $customFieldsData)
    {
        foreach ($customFieldsData as $key => $value) {
            $def = CustomFieldDefinition::where('entity_type', 'vehicle')->where('key', $key)->first();
            
            if ($def) {
                $valData = ['value' => null, 'value_text' => null, 'value_number' => null, 'value_date' => null];
                
                if ($def->type === 'number') {
                    $valData['value_number'] = $value;
                } elseif ($def->type === 'date') {
                    $valData['value_date'] = $value;
                } elseif ($def->type === 'select' || $def->type === 'text') {
                    $valData['value_text'] = is_array($value) ? implode(', ', $value) : $value;
                } elseif ($def->type === 'checkbox') {
                    $valData['value_text'] = $value ? '1' : '0';
                } else {
                    $valData['value'] = json_encode($value);
                }

                CustomFieldValue::updateOrCreate(
                    [
                        'custom_field_definition_id' => $def->id,
                        'entity_type' => 'vehicle',
                        'entity_id' => $vehicle->id,
                    ],
                    $valData
                );
            }
        }
    }
}
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Branch;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\ListView;
use App\Services\FieldPermissionService;
use App\Services\CountryConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        
        // Получаем клиентов с их филиалами и группами
        $clients = Client::with(['branch', 'group'])->orderBy('id', 'desc')->get();
        $branches = Branch::where('is_active', true)->get(['id', 'name']);
        $clientGroups = ClientGroup::orderBy('name')->get();

        $tenantCountry = config('tenant.country_code', 'RU');
        $countryConfig = CountryConfigService::getForCountry($tenantCountry);

        // --- ДИНАМИЧЕСКИЕ ТАБЛИЦЫ И КАСТОМНЫЕ ПОЛЯ ---
        
        // 1. Формируем базовый список системных колонок
        $baseColumns = [
            ['key' => 'client_name', 'label' => 'Клиент', 'type' => 'system', 'is_default' => true],
            ['key' => 'client_group', 'label' => 'Роль / Группа', 'type' => 'system', 'is_default' => true],
            ['key' => 'phone', 'label' => 'Телефон', 'type' => 'system', 'is_default' => true],
            ['key' => 'phone_2', 'label' => 'Доп. Телефон', 'type' => 'system', 'is_default' => false],
            ['key' => 'email', 'label' => 'Email', 'type' => 'system', 'is_default' => false],
            ['key' => 'type', 'label' => 'Тип (B2B/B2C)', 'type' => 'system', 'is_default' => true],
            ['key' => 'source', 'label' => 'Источник', 'type' => 'system', 'is_default' => false],
            ['key' => 'balance', 'label' => 'Баланс', 'type' => 'system', 'is_default' => true],
            ['key' => 'bonus_points', 'label' => 'Бонусы', 'type' => 'system', 'is_default' => false],
            ['key' => 'discount_percent', 'label' => 'Скидка (%)', 'type' => 'system', 'is_default' => false],
            ['key' => 'branch', 'label' => 'Филиал', 'type' => 'system', 'is_default' => true],
        ];

        // 2. Подмешиваем кастомные поля для Клиентов
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'client')->orderBy('sort_order')->get();
        foreach ($customFieldDefs as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        // 3. Загружаем значения кастомных полей для всех клиентов одним запросом
        $cfValues = CustomFieldValue::where('entity_type', 'client')
            ->whereIn('entity_id', $clients->pluck('id'))
            ->get();

        // 4. Мапим значения кастомных полей внутрь объектов клиентов для удобства Vue
        $clients->transform(function ($client) use ($cfValues, $customFieldDefs) {
            $clientData = $client->toArray();
            $clientData['custom_fields'] = [];
            
            foreach ($customFieldDefs as $def) {
                $val = $cfValues->where('entity_id', $client->id)->where('custom_field_definition_id', $def->id)->first();
                $clientData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }
            
            return $clientData;
        });

        // 5. Фильтруем колонки через FieldPermissionService (отсекаем те, к которым нет прав)
        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'client', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        // 6. Получаем сохраненный вид таблицы для текущего пользователя
        $listView = ListView::where('entity_type', 'client')
            ->where('user_id', $user->id)
            ->first();

        // Если вида нет, берем дефолтные колонки из доступных
        $visibleColumns = $listView 
            ? $listView->visible_columns 
            : array_values(array_map(fn($c) => $c['key'], array_filter($availableColumns, fn($c) => $c['is_default'])));

        return Inertia::render('CRM/Clients/Index', [
            'clients' => $clients,
            'branches' => $branches,
            'clientGroups' => $clientGroups,
            'customFieldDefs' => $customFieldDefs,
            'availableColumns' => $availableColumns,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
            'tenantCountry' => $tenantCountry,
            'countryConfig' => $countryConfig,
        ]);
    }

    public function show(Client $client): Response
    {
        $client->load(['branch', 'group', 'vehicles']);
        
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'client')->orderBy('sort_order')->get();
        $cfValues = CustomFieldValue::where('entity_type', 'client')->where('entity_id', $client->id)->get();
        
        $customFieldsData = [];
        foreach ($customFieldDefs as $def) {
            $val = $cfValues->where('custom_field_definition_id', $def->id)->first();
            $customFieldsData[] = [
                'definition' => $def,
                'value' => $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null,
            ];
        }

        $tenantCountry = config('tenant.country_code', 'RU');
        $countryConfig = CountryConfigService::getForCountry($tenantCountry);

        return Inertia::render('CRM/Clients/Show', [
            'client' => $client,
            'customFieldsData' => $customFieldsData,
            'tenantCountry' => $tenantCountry,
            'countryConfig' => $countryConfig,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_group_id' => ['nullable', 'exists:client_groups,id'],
            'is_lead' => ['boolean'],
            'type' => ['required', 'string', 'in:b2c,b2b'],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'phone_2' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
            'discount_percent' => ['integer', 'min:0', 'max:100'],
            'requisites' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated) {
            $client = Client::create([
                'branch_id' => $validated['branch_id'],
                'client_group_id' => $validated['client_group_id'] ?? null,
                'is_lead' => $validated['is_lead'] ?? false,
                'type' => $validated['type'],
                'name' => $validated['name'],
                'alias' => $validated['alias'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'phone_2' => $validated['phone_2'] ?? null,
                'email' => $validated['email'] ?? null,
                'source' => $validated['source'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'discount_percent' => $validated['discount_percent'] ?? 0,
                'requisites' => $validated['requisites'] ?? null,
            ]);

            if (!empty($validated['custom_fields'])) {
                $this->saveCustomFields($client, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Клиент успешно добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_group_id' => ['nullable', 'exists:client_groups,id'],
            'is_lead' => ['boolean'],
            'type' => ['required', 'string', 'in:b2c,b2b'],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'phone_2' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
            'discount_percent' => ['integer', 'min:0', 'max:100'],
            'requisites' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated, $client) {
            $client->update([
                'branch_id' => $validated['branch_id'],
                'client_group_id' => $validated['client_group_id'] ?? null,
                'is_lead' => $validated['is_lead'] ?? false,
                'type' => $validated['type'],
                'name' => $validated['name'],
                'alias' => $validated['alias'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'phone_2' => $validated['phone_2'] ?? null,
                'email' => $validated['email'] ?? null,
                'source' => $validated['source'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'discount_percent' => $validated['discount_percent'] ?? 0,
                'requisites' => $validated['requisites'] ?? null,
            ]);

            if (isset($validated['custom_fields'])) {
                $this->saveCustomFields($client, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Данные клиента обновлены');
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return redirect()->back()->with('success', 'Клиент удален');
    }

    public function storeGroup(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);

        ClientGroup::create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? 'gray',
        ]);

        return redirect()->back()->with('success', 'Группа добавлена');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:clients,id'],
        ]);

        Client::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные клиенты удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:clients,id'],
        ]);

        $clients = Client::with(['branch', 'group'])->whereIn('id', $validated['ids'])->get();
        
        $filename = 'clients_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];
        
        $callback = function() use($clients) {
            $file = fopen('php://output', 'w');
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            fputcsv($file, ['ID', 'Имя', 'Псевдоним', 'Телефон', 'Доп. Телефон', 'Email', 'Тип', 'Группа', 'Источник', 'Баланс', 'Бонусы', 'Скидка', 'Филиал'], ';');
            
            foreach ($clients as $client) {
                fputcsv($file, [
                    $client->id,
                    $client->name,
                    $client->alias,
                    $client->phone,
                    $client->phone_2,
                    $client->email,
                    $client->type === 'b2b' ? 'Юрлицо' : 'Физлицо',
                    $client->group ? $client->group->name : 'Без группы',
                    $client->source,
                    $client->balance / 100, // Конвертация копеек в рубли
                    $client->bonus_points,
                    $client->discount_percent . '%',
                    $client->branch ? $client->branch->name : ''
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function saveCustomFields(Client $client, array $customFieldsData)
    {
        foreach ($customFieldsData as $key => $value) {
            $def = CustomFieldDefinition::where('entity_type', 'client')->where('key', $key)->first();
            
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
                        'entity_type' => 'client',
                        'entity_id' => $client->id,
                    ],
                    $valData
                );
            }
        }
    }
}
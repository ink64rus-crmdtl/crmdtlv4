<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\DocumentTemplate;
use App\Models\ListView;
use App\Models\Lookup;
use App\Models\WorkOrder;
use App\Services\ActivityLogger;
use App\Services\ClientSegmentService;
use App\Services\CountryConfigService;
use App\Services\FieldPermissionService;
use App\Services\LoyaltyGradeService;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Базовый запрос с подгрузкой связей
        $query = Client::with(['branch' => fn ($q) => $q->withTrashed(), 'group', 'roles']);
        ClientSegmentService::withAggregates($query);

        // Фильтр по RFM-сегменту (Фаза 14.3) — не обычная колонка таблицы,
        // обрабатывается отдельно через HAVING на агрегатах, а не в общем
        // QueryFilterService (тот сделал бы наивный WHERE segment = ...).
        $requestParams = $request->all();
        $segmentFilter = $requestParams['filters']['segment'] ?? null;
        if ($segmentFilter) {
            unset($requestParams['filters']['segment']);
        }

        // Фильтр по роли (многие-ко-многим, client_roles) — тоже не обычная
        // колонка, colонки role на clients больше нет (см. миграцию
        // 2027_01_30). Значение — id одной или нескольких Lookup-записей.
        $roleFilter = $requestParams['filters']['role'] ?? null;
        if ($roleFilter) {
            unset($requestParams['filters']['role']);
        }

        // Применяем серверную фильтрацию и поиск
        $query = QueryFilterService::apply(
            $query,
            $requestParams,
            ['name', 'phone', 'email', 'alias', 'vehicles.plate_number'],
            'client',
            allowedSorts: ['name', 'phone', 'phone_2', 'email', 'type', 'source', 'balance', 'bonus_points', 'discount_percent']
        );

        if ($segmentFilter) {
            ClientSegmentService::applyFilter($query, $segmentFilter);
        }

        if ($roleFilter) {
            $roleIds = is_array($roleFilter) ? $roleFilter : [$roleFilter];
            $query->whereHas('roles', fn ($q) => $q->whereIn('lookups.id', $roleIds));
        }

        if (! $request->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        // Если AJAX-запрос для SearchableSelect, возвращаем только пагинированные данные (Исключаем Inertia)
        if (($request->wantsJson() || $request->ajax()) && ! $request->hasHeader('X-Inertia')) {
            return response()->json($query->paginate(15));
        }

        // Пагинация вместо ->get()
        $clients = $query->paginate(15)->withQueryString();

        $branches = Branch::forSelect()->get(['id', 'name']);
        $clientGroups = ClientGroup::orderBy('name')->get();
        $lookups = Lookup::whereIn('type', ['client_source', 'client_role'])->where('is_active', true)->get()->groupBy('type');

        $tenantCountry = config('tenant.country_code', 'RU');
        $countryConfig = CountryConfigService::getForCountry($tenantCountry);

        // --- ДИНАМИЧЕСКИЕ ТАБЛИЦЫ И КАСТОМНЫЕ ПОЛЯ ---

        // 1. Формируем базовый список системных колонок
        $baseColumns = [
            ['key' => 'client_name', 'label' => 'Клиент', 'type' => 'system', 'is_default' => true],
            ['key' => 'client_group', 'label' => 'Группа лояльности', 'type' => 'system', 'is_default' => true],
            ['key' => 'role', 'label' => 'Роль клиента', 'type' => 'system', 'is_default' => false],
            ['key' => 'segment', 'label' => 'Сегмент', 'type' => 'system', 'is_default' => true],
            ['key' => 'phone', 'label' => 'Телефон', 'type' => 'system', 'is_default' => true],
            ['key' => 'phone_2', 'label' => 'Доп. Телефон', 'type' => 'system', 'is_default' => false],
            ['key' => 'email', 'label' => 'Email', 'type' => 'system', 'is_default' => false],
            ['key' => 'type', 'label' => 'Тип (B2B/B2C)', 'type' => 'system', 'is_default' => true],
            ['key' => 'source', 'label' => 'Источник', 'type' => 'system', 'is_default' => false],
            ['key' => 'balance', 'label' => 'Баланс', 'type' => 'system', 'is_default' => true],
            ['key' => 'bonus_points', 'label' => 'Бонусы', 'type' => 'system', 'is_default' => false],
            ['key' => 'discount_percent', 'label' => 'Скидка (%)', 'type' => 'system', 'is_default' => false],
            ['key' => 'branch', 'label' => 'Локация', 'type' => 'system', 'is_default' => true],
        ];

        // 2. Подмешиваем кастомные поля для  Клиентов
        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'client')->orderBy('sort_order')->get();
        foreach ($customFieldDefs as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        // 3. Загружаем значения кастомных полей только для клиентов на текущей странице
        $cfValues = CustomFieldValue::where('entity_type', 'client')
            ->whereIn('entity_id', $clients->getCollection()->pluck('id'))
            ->get();

        // 4. Мапим значения кастомных полей внутрь объектов клиентов для удобства Vue
        $clients->getCollection()->transform(function ($client) use ($cfValues, $customFieldDefs) {
            $clientData = $client->toArray();
            $clientData['custom_fields'] = [];

            foreach ($customFieldDefs as $def) {
                $val = $cfValues->where('entity_id', $client->id)->where('custom_field_definition_id', $def->id)->first();
                $clientData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }

            $segment = ClientSegmentService::classify($client);
            $clientData['segment'] = $segment;
            $clientData['segment_label'] = ClientSegmentService::label($segment);

            return $clientData;
        });

        // 5. Фильтруем колонки через FieldPermissionService (отсекаем те, к которым нет прав)
        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'client', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function ($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        // 6. Получаем сохраненный вид таблицы для текущего пользователя
        $listView = ListView::where('entity_type', 'client')
            ->where('user_id', $user->id)
            ->first();

        // Если вида нет, берем дефолтные колонки из доступных
        $visibleColumns = $listView
            ? $listView->visible_columns
            : array_values(array_map(fn ($c) => $c['key'], array_filter($availableColumns, fn ($c) => $c['is_default'])));

        return Inertia::render('CRM/Clients/Index', [
            'clients' => $clients,
            'filters' => $request->all(),
            'branches' => $branches,
            'clientGroups' => $clientGroups,
            'lookups' => $lookups,
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
        // Загружаем автомобили вместе с марками и моделями
        $client->load(['branch' => fn ($q) => $q->withTrashed(), 'group', 'roles', 'vehicles.make', 'vehicles.vehicleModel', 'documents' => fn ($q) => $q->with(['documentable', 'branch.legalEntities', 'supersededBy:id,number'])->orderBy('id', 'desc')]);
        $client->documents->each->append('is_stale');

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

        // Данные для модального окна редактирования
        $branches = Branch::forSelect()->get(['id', 'name']);
        $clientGroups = ClientGroup::orderBy('name')->get();
        $lookups = Lookup::whereIn('type', ['client_source', 'client_role'])->where('is_active', true)->get()->groupBy('type');

        $workOrders = WorkOrder::where('client_id', $client->id)
            ->with(['vehicle.make', 'vehicle.vehicleModel'])
            ->orderByDesc('id')
            ->get(['id', 'branch_id', 'client_id', 'vehicle_id', 'status', 'payment_status', 'final_amount', 'created_at']);

        $workOrderStatuses = Lookup::where('type', 'work_order_status')->orderBy('sort_order')->get(['value', 'label', 'color']);

        // RFM-сегмент (Фаза 14.3) — по уже выгруженным заказам клиента, без
        // повторного агрегатного запроса.
        $completedOrders = $workOrders->where('status', 'completed');
        $clientSegment = ClientSegmentService::classifyFromCounts($completedOrders->count(), $completedOrders->max('created_at'));

        // "История"/"Комментарии" с roll-up: подтягиваются не только события
        // самого клиента, но и события связанных Записей и Заказ-нарядов
        // (по client_id в properties, см. App\Services\ActivityLogger).
        ['activities' => $activities, 'comments' => $comments] = ActivityLogger::present(ActivityLogger::feedFor($client, 'client_id'));

        $client->setAttribute('segment', $clientSegment);
        $client->setAttribute('segment_label', ClientSegmentService::label($clientSegment));

        return Inertia::render('CRM/Clients/Show', [
            'client' => $client,
            'customFieldsData' => $customFieldsData,
            'tenantCountry' => $tenantCountry,
            'countryConfig' => $countryConfig,
            'branches' => $branches,
            'clientGroups' => $clientGroups,
            'lookups' => $lookups,
            'customFieldDefs' => $customFieldDefs,
            'workOrders' => $workOrders,
            'workOrderStatuses' => $workOrderStatuses,
            'activities' => $activities,
            'comments' => $comments,
            'messengerChannels' => Channel::where('is_active', true)
                ->whereIn('provider', ['wappi_pro', 'green_api'])
                ->get(['id', 'name', 'messenger_type']),
            'documentTemplates' => DocumentTemplate::where('entity_type', 'client')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        // Телефон обязателен по умолчанию (иначе непонятно, как узнать,
        // что клиент уже есть в системе) — но менеджер может явно снять
        // требование тумблером на форме (phone_required=false), например
        // для лида без номера; фронт в этом случае обязан показать
        // предупреждение о риске задвоить клиента (не проверяется здесь).
        $phoneRequired = $request->boolean('phone_required', true);

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_group_id' => ['nullable', 'exists:client_groups,id'],
            'is_lead' => ['boolean'],
            'type' => ['required', 'string', 'in:b2c,b2b'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('lookups', 'id')->where('type', 'client_role')],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:255'],
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
                // Группу выбрали явно при заведении клиента — считаем это ручным
                // решением (LoyaltyGradeService её больше не тронет). Оставили
                // пустой ("Без группы") — можно, автоподбор начнёт работать
                // с первой же оплаты, если клиент наберёт нужный оборот/заказы.
                'client_group_locked' => ! empty($validated['client_group_id']),
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

            $client->roles()->sync($validated['role_ids'] ?? []);

            if (! empty($validated['custom_fields'])) {
                $this->saveCustomFields($client, $validated['custom_fields']);
            }

            ActivityLogger::log($client, 'Клиент создан', [], 'created');
        });

        return redirect()->back()->with('success', 'Клиент успешно добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $phoneRequired = $request->boolean('phone_required', true);

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_group_id' => ['nullable', 'exists:client_groups,id'],
            'is_lead' => ['boolean'],
            'type' => ['required', 'string', 'in:b2c,b2b'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('lookups', 'id')->where('type', 'client_role')],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:255'],
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
            $newGroupId = $validated['client_group_id'] ?? null;
            // Лочим автоподбор, ТОЛЬКО если группу реально поменяли этим
            // сохранением — если поле просто "проехало" с тем же значением
            // (правили что-то другое в форме), ручного решения тут не было.
            $groupChanged = $newGroupId != $client->client_group_id;

            $client->update([
                'branch_id' => $validated['branch_id'],
                'client_group_id' => $newGroupId,
                'client_group_locked' => $groupChanged ? true : $client->client_group_locked,
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

            $client->roles()->sync($validated['role_ids'] ?? []);

            if (isset($validated['custom_fields'])) {
                $this->saveCustomFields($client, $validated['custom_fields']);
            }

            ActivityLogger::log($client, 'Данные клиента обновлены', [], 'updated');
        });

        return redirect()->back()->with('success', 'Данные клиента обновлены');
    }

    /**
     * Возврат клиента под автоподбор грейда (LoyaltyGradeService) после
     * ручного выбора группы — снимает лок и сразу пересчитывает, чтобы
     * менеджер увидел результат немедленно, не дожидаясь следующей оплаты.
     */
    public function resetGroupToAuto(Client $client)
    {
        $client->update(['client_group_locked' => false]);
        LoyaltyGradeService::evaluate($client);

        return redirect()->back()->with('success', 'Группа клиента возвращена на автоподбор');
    }

    public function addComment(Request $request, Client $client)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        ActivityLogger::log($client, $validated['comment'], [], 'comment');

        return redirect()->back()->with('success', 'Комментарий добавлен');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->back()->with('success', 'Клиент удален');
    }

    /**
     * Правила автоподбора (min_turnover_amount/min_orders_count/
     * auto_assign_period_days/sort_order) сюда не входят намеренно — эта
     * форма используется и в лёгкой модалке "добавить группу на лету" прямо
     * из карточки клиента (CRM/Clients/Index.vue), где полноценная настройка
     * правил была бы лишней; полное управление — Settings/Loyalty/Index.vue,
     * там поля дополнительно шлются и попадают сюда же через $request->only().
     */
    private function validateGroupBasics(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
            'cashback_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_turnover_amount' => ['nullable', 'numeric', 'min:0'],
            'min_orders_count' => ['nullable', 'integer', 'min:0'],
            'auto_assign_period_days' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer'],
        ]);
    }

    private function groupAttributesFrom(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'color' => $validated['color'] ?? 'gray',
            'cashback_percent' => $validated['cashback_percent'] ?? 0,
            'discount_percent' => $validated['discount_percent'] ?? 0,
            // Копейки — форма присылает рубли.
            'min_turnover_amount' => isset($validated['min_turnover_amount']) && $validated['min_turnover_amount'] !== ''
                ? (int) round($validated['min_turnover_amount'] * 100) : null,
            'min_orders_count' => $validated['min_orders_count'] ?? null,
            'auto_assign_period_days' => $validated['auto_assign_period_days'] ?? 90,
            'sort_order' => $validated['sort_order'] ?? 0,
        ];
    }

    public function storeGroup(Request $request)
    {
        $validated = $this->validateGroupBasics($request);

        ClientGroup::create($this->groupAttributesFrom($validated));

        return redirect()->back()->with('success', 'Группа добавлена');
    }

    public function updateGroup(Request $request, ClientGroup $clientGroup)
    {
        $validated = $this->validateGroupBasics($request);

        $clientGroup->update($this->groupAttributesFrom($validated));

        return redirect()->back()->with('success', 'Группа обновлена');
    }

    public function destroyGroup(ClientGroup $clientGroup)
    {
        // client_group_id у Client — nullOnDelete: у клиентов группа просто
        // сбрасывается на "Без группы", без ошибки внешнего ключа.
        $clientGroup->delete();

        return redirect()->back()->with('success', 'Группа удалена');
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

        ExportEntitiesJob::dispatch('clients', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
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

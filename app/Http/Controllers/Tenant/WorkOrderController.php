<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BusinessDirection;
use App\Models\Client;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\ListView;
use App\Models\Lookup;
use App\Models\MessageTemplate;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\BranchScope;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ActivityLogger;
use App\Services\Documents\DocumentPlaceholderService;
use App\Services\FieldPermissionService;
use App\Services\FinanceService;
use App\Services\LoyaltyGradeService;
use App\Services\Messaging\ChatDispatchService;
use App\Services\Messaging\MessageTemplateService;
use App\Services\PayrollCalculationService;
use App\Services\QueryFilterService;
use App\Services\StockService;
use App\Services\TimezoneResolver;
use App\Services\WarehouseResolver;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    /**
     * Значение системной роли клиента (Lookup type=client_role, is_system),
     * дающей право быть исполнителем услуги наравне со штатным сотрудником.
     * Заводится миграцией 2027_01_30_000000_create_client_roles_table.
     * Это value-слаг (миграция 2027_02_02 развела value/label по образцу
     * work_order_status), не отображаемый текст — CONTRACTOR_ROLE_LABEL ниже.
     * LookupController::update() блокирует правку is_system-записей ПОЛНОСТЬЮ,
     * включая эти три роли (переименование сознательно отключено — риск, что
     * кто-то поменяет текст не подумав, оказался важнее удобства), поэтому
     * слаг и текст гарантированно не разъедутся с тем, что видит пользователь.
     */
    private const CONTRACTOR_ROLE = 'contractor';

    /**
     * Отображаемый текст роли для сообщений об ошибке. Захардкожен константой,
     * а не читается из БД: с тех пор как переименование системных ролей
     * запрещено (см. выше), label гарантированно совпадает с этим текстом
     * всегда, и лишний запрос ради «а вдруг переименовали» не нужен.
     */
    private const CONTRACTOR_ROLE_LABEL = 'Подрядчик';

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $query = WorkOrder::with(['branch' => fn ($q) => $q->withTrashed(), 'legalEntity' => fn ($q) => $q->withTrashed(), 'client', 'vehicle.make', 'vehicle.vehicleModel'])
            // Для иконки "Принять оплату" и остатка долга прямо в списке —
            // O(1) SQL-подзапрос на страницу (withSum), не N+1 по заказам
            // (CLAUDE.md, п.6). Только income — та же выборка, что и в
            // WorkOrder::syncPaymentStatus()/processPayment().
            ->withSum(['transactions as paid_amount' => fn ($q) => $q->where('type', 'income')], 'amount');

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['id', 'client.name', 'client.phone', 'vehicle.plate_number'],
            'work_order',
            allowedSorts: ['id', 'created_at', 'status', 'payment_status', 'final_amount', 'mileage']
        );

        if (! request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $workOrders = $query->paginate(15)->withQueryString();

        $branches = Branch::forSelect()->with('legalEntities:id,name')->get(['id', 'name']);
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone']);
        $vehicles = Vehicle::with(['make', 'vehicleModel'])->get(['id', 'client_id', 'vehicle_make_id', 'vehicle_model_id', 'plate_number']);
        $makes = VehicleMake::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $models = VehicleModel::where('is_active', true)->orderBy('name')->get(['id', 'vehicle_make_id', 'name']);

        // Для модалки "Принять оплату" прямо из списка (тот же набор, что и
        // у show() — WorkOrderController::processPayment() их и использует).
        $accounts = $user->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.commission_percent']);
        $bonusRubPerPoint = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);

        $baseColumns = [
            ['key' => 'id', 'label' => '№ Заказа', 'type' => 'system', 'is_default' => true],
            ['key' => 'created_at', 'label' => 'Дата', 'type' => 'system', 'is_default' => true],
            ['key' => 'client', 'label' => 'Клиент', 'type' => 'system', 'is_default' => true],
            ['key' => 'vehicle', 'label' => 'Автомобиль', 'type' => 'system', 'is_default' => true],
            ['key' => 'status', 'label' => 'Статус', 'type' => 'system', 'is_default' => true],
            ['key' => 'payment_status', 'label' => 'Оплата', 'type' => 'system', 'is_default' => true],
            ['key' => 'final_amount', 'label' => 'Сумма', 'type' => 'system', 'is_default' => true],
            ['key' => 'branch', 'label' => 'Локация', 'type' => 'system', 'is_default' => false],
            ['key' => 'mileage', 'label' => 'Пробег', 'type' => 'system', 'is_default' => false],
        ];

        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'work_order')->orderBy('sort_order')->get();
        foreach ($customFieldDefs as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        $cfValues = CustomFieldValue::where('entity_type', 'work_order')
            ->whereIn('entity_id', $workOrders->getCollection()->pluck('id'))
            ->get();

        $workOrders->getCollection()->transform(function ($order) use ($cfValues, $customFieldDefs) {
            $orderData = $order->toArray();
            $orderData['custom_fields'] = [];

            foreach ($customFieldDefs as $def) {
                $val = $cfValues->where('entity_id', $order->id)->where('custom_field_definition_id', $def->id)->first();
                $orderData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }

            return $orderData;
        });

        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'work_order', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function ($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        $listView = ListView::where('entity_type', 'work_order')
            ->where('user_id', $user->id)
            ->first();

        $visibleColumns = $listView
            ? $listView->visible_columns
            : array_values(array_map(fn ($c) => $c['key'], array_filter($availableColumns, fn ($c) => $c['is_default'])));

        return Inertia::render('Operations/WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->all(),
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'makes' => $makes,
            'models' => $models,
            'customFieldDefs' => $customFieldDefs,
            'availableColumns' => $availableColumns,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
            'workOrderStatuses' => $this->workOrderStatuses(),
            'accounts' => $accounts,
            'bonusRubPerPoint' => $bonusRubPerPoint,
        ]);
    }

    public function show(WorkOrder $workOrder): Response
    {
        $workOrder->load(['branch' => fn ($q) => $q->withTrashed(), 'legalEntity' => fn ($q) => $q->withTrashed(), 'client', 'vehicle.make', 'vehicle.vehicleModel', 'items.employees', 'items.contractors', 'items.admins', 'transactions.account', 'admins', 'documents' => fn ($q) => $q->with(['documentable', 'branch.legalEntities', 'supersededBy:id,number'])->orderBy('id', 'desc')]);
        $workOrder->documents->each->append('is_stale');

        $customFieldDefs = CustomFieldDefinition::where('entity_type', 'work_order')->orderBy('sort_order')->get();
        $cfValues = CustomFieldValue::where('entity_type', 'work_order')->where('entity_id', $workOrder->id)->get();

        $customFieldsData = [];
        foreach ($customFieldDefs as $def) {
            $val = $cfValues->where('custom_field_definition_id', $def->id)->first();
            $customFieldsData[] = [
                'definition' => $def,
                'value' => $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null,
            ];
        }

        $branches = Branch::forSelect()->with('legalEntities:id,name')->get(['id', 'name']);
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone']);
        $vehicles = Vehicle::with(['make', 'vehicleModel'])->get(['id', 'client_id', 'vehicle_make_id', 'vehicle_model_id', 'plate_number']);

        $services = Service::where('is_active', true)->get(['id', 'name', 'price', 'prices', 'service_category_id', 'business_direction_id']);
        $products = Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'product_category_id', 'base_price', 'discount_percent']);

        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.commission_percent']);

        $pricingBasis = Setting::where('key', 'pricing_basis')->value('value') ?? 'none';
        $bonusRubPerPoint = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
        $lookups = Lookup::whereIn('type', ['vehicle_body', 'vehicle_class'])->where('is_active', true)->get()->groupBy('type');
        $businessDirections = BusinessDirection::where('is_active', true)->get(['id', 'name']);
        $serviceCategories = ServiceCategory::where('is_active', true)->get(['id', 'name', 'business_direction_id']);
        $productCategories = ProductCategory::where('is_active', true)->get(['id', 'name']);
        $employees = Employee::where('is_active', true)->with('position:id,payroll_role')->get(['id', 'first_name', 'last_name', 'type', 'position_id']);

        // Подрядчики, доступные для назначения исполнителем услуги — только
        // клиенты с системной ролью «Подрядчик» (см. assertAssigneeIdsExist()).
        $contractors = Client::whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::CONTRACTOR_ROLE))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        // Фаза 9.4: связь с записью, из которой создан заказ (если создан через
        // конвертацию), либо — если заказ создан напрямую — подсказка привязать
        // подходящую незакрытую запись того же клиента, найденную в календаре.
        $linkedAppointment = Appointment::where('work_order_id', $workOrder->id)->first(['id', 'branch_id', 'start_at']);
        if ($linkedAppointment) {
            $tz = TimezoneResolver::forBranch($linkedAppointment->branch_id);
            $linkedAppointment->setAttribute('start_at_local', $linkedAppointment->start_at->copy()->setTimezone($tz)->format('d.m.Y H:i'));
        }

        // Лента "История"/"Комментарии" — записывается вручную через ActivityLogger
        // в местах, где что-то реально произошло, не автологированием диффов
        // полей. Комментарии (event = 'comment') отдельно от системных событий —
        // см. CLAUDE.md, п.7.
        ['activities' => $activities, 'comments' => $comments] = ActivityLogger::present(ActivityLogger::feedFor($workOrder));

        $candidateAppointment = null;
        if (! $linkedAppointment) {
            $now = now();
            $candidateAppointment = Appointment::where('client_id', $workOrder->client_id)
                ->whereIn('status', ['scheduled', 'confirmed'])
                ->get(['id', 'branch_id', 'start_at'])
                ->sortBy(fn (Appointment $a) => abs($a->start_at->diffInSeconds($now)))
                ->first();

            if ($candidateAppointment) {
                $tz = TimezoneResolver::forBranch($candidateAppointment->branch_id);
                $candidateAppointment->setAttribute('start_at_local', $candidateAppointment->start_at->copy()->setTimezone($tz)->format('d.m.Y H:i'));
            }
        }

        return Inertia::render('Operations/WorkOrders/Show', [
            'workOrder' => $workOrder,
            'customFieldsData' => $customFieldsData,
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'services' => $services,
            'products' => $products,
            'accounts' => $accounts,
            'customFieldDefs' => $customFieldDefs,
            'pricingBasis' => $pricingBasis,
            'bonusRubPerPoint' => $bonusRubPerPoint,
            'lookups' => $lookups,
            'businessDirections' => $businessDirections,
            'serviceCategories' => $serviceCategories,
            'productCategories' => $productCategories,
            'employees' => $employees,
            'contractors' => $contractors,
            'workOrderStatuses' => $this->workOrderStatuses(),
            'linkedAppointment' => $linkedAppointment,
            'candidateAppointment' => $candidateAppointment,
            'activities' => $activities,
            'comments' => $comments,
            'documentTemplates' => DocumentTemplate::where('entity_type', 'work_order')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    /**
     * Точка теперь может иметь несколько юрлиц (branch_legal_entity) —
     * серверная проверка, что выбранное юрлицо реально из НИХ, а не любое
     * в системе (фронт и так фильтрует список, это защита от подмены
     * запроса напрямую, не полагаемся только на клиентский UI).
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id', $this->legalEntityBelongsToBranchRule($request)],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated) {
            // Автоназначение администратора заказа: если создатель — сотрудник
            // на должности с ролью "администратор" (payroll_role), он сразу
            // прикрепляется единственным админом заказа (work_order_admins) —
            // ЗП по каждой позиции считается от него. Менеджер может добавить
            // ещё админов или переопределить вручную в любой момент — тогда
            // admin_assignment_mode переключится на 'manual' и автоматика
            // больше не будет перезаписывать выбор.
            $creatorEmployee = Employee::where('user_id', auth()->id())->with('position')->first();
            $defaultAdminEmployeeId = ($creatorEmployee && $creatorEmployee->position?->payroll_role === 'admin')
                ? $creatorEmployee->id
                : null;

            $workOrder = WorkOrder::create([
                'branch_id' => $validated['branch_id'],
                'legal_entity_id' => $validated['legal_entity_id'] ?? null,
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'status' => $validated['status'],
                'payment_status' => 'unpaid',
                'mileage' => $validated['mileage'] ?? null,
                'total_amount' => 0,
                'discount_amount' => 0,
                'final_amount' => 0,
                'created_by' => auth()->id(),
                'admin_assignment_mode' => 'auto',
            ]);

            if ($defaultAdminEmployeeId) {
                $workOrder->admins()->attach($defaultAdminEmployeeId);
            }

            if (! empty($validated['custom_fields'])) {
                $this->saveCustomFields($workOrder, $validated['custom_fields']);
            }

            ActivityLogger::log($workOrder, "Заказ-наряд №{$workOrder->id} создан", $this->workOrderLink($workOrder), 'created');
        });

        return redirect()->back()->with('success', 'Заказ-наряд успешно создан');
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id', $this->legalEntityBelongsToBranchRule($request)],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated, $workOrder) {
            $workOrder->update([
                'branch_id' => $validated['branch_id'],
                'legal_entity_id' => $validated['legal_entity_id'] ?? null,
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'status' => $validated['status'],
                'mileage' => $validated['mileage'] ?? null,
            ]);

            if (isset($validated['custom_fields'])) {
                $this->saveCustomFields($workOrder, $validated['custom_fields']);
            }
        });

        return redirect()->back()->with('success', 'Заказ-наряд обновлен');
    }

    public function destroy(WorkOrder $workOrder)
    {
        // WorkOrder — SoftDeletes, поэтому ->delete() НЕ триггерит ни
        // cascadeOnDelete (work_order_items), ни nullOnDelete (stock_movements,
        // payrolls) на уровне БД — строка физически остаётся, просто прячется
        // из выборок с default scope. Раньше это позволяло "удалить" уже
        // завершённый заказ с проведённой оплатой, списанным со склада
        // материалом и начисленной за него зарплатой: деньги/остатки/начисление
        // оставались в силе, но заказ, который их объясняет, пропадал из
        // интерфейса — см. правило CLAUDE.md §6 об операциях, необратимо
        // повлиявших на склад/финансы: для них нужен явный откат через
        // StockService/FinanceService, а не просто снятие блокировки (поэтому
        // здесь нет обхода даже для admin).
        if ($workOrder->transactions()->exists() || StockMovement::where('work_order_id', $workOrder->id)->exists() || Payroll::where('work_order_id', $workOrder->id)->exists()) {
            return redirect()->back()->withErrors(['error' => "Заказ №{$workOrder->id} нельзя удалить: по нему уже проведена оплата, списание со склада или начисление зарплаты. Сначала отмените эти операции (возврат оплаты в Финансах, начисление ЗП — в разделе Зарплата), либо переведите заказ в статус «Отменён» вместо удаления."]);
        }

        $this->unlinkAppointments([$workOrder->id]);
        $workOrder->delete();

        return redirect()->back()->with('success', 'Заказ-наряд удален');
    }

    public function updateStatus(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
        ]);

        $previousStatus = $workOrder->status;
        $workOrder->update(['status' => $validated['status']]);

        $statusLabel = Lookup::where('type', 'work_order_status')->where('value', $validated['status'])->value('label') ?? $validated['status'];
        ActivityLogger::log($workOrder, "Статус заказа №{$workOrder->id} изменён на «{$statusLabel}»", $this->workOrderLink($workOrder), 'status_changed');

        // Фаза 11.1: автоуведомление клиента при переходе В "ready" (не на
        // каждое сохранение уже стоящего статуса — иначе повторный клик по
        // тому же пункту в списке рассылал бы дубли).
        if ($validated['status'] === 'ready' && $previousStatus !== 'ready') {
            $this->notifyClientByTrigger($workOrder, 'work_order.ready', fn ($template) => MessageTemplateService::renderForWorkOrder($template, $workOrder));
        }

        return redirect()->back()->with('success', 'Статус обновлён');
    }

    /**
     * Общая точка автотриггеров по шаблонам (Фаза 11.1) — молча пропускает,
     * если шаблон для триггера не настроен/выключен или у клиента нет
     * телефона/доступного канала: это не ошибка бизнес-операции (заказ всё
     * равно должен смениться статус), просто уведомление некому отправить.
     */
    private function notifyClientByTrigger(WorkOrder $workOrder, string $trigger, \Closure $renderBody): void
    {
        $template = MessageTemplate::where('event_trigger', $trigger)->where('is_active', true)->first();
        if (! $template || ! $workOrder->client) {
            return;
        }

        $channel = ChatDispatchService::defaultChannelFor($workOrder->branch_id);
        if (! $channel) {
            return;
        }

        ChatDispatchService::sendToClient($workOrder->client, $channel, $renderBody($template));
    }

    public function addComment(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        ActivityLogger::log($workOrder, $validated['comment'], $this->workOrderLink($workOrder), 'comment');

        return redirect()->back()->with('success', 'Комментарий добавлен');
    }

    /**
     * Ручное назначение/снятие администраторов заказа (Фаза 10.1, многие-ко-многим,
     * work_order_admins) — у части клиентов на заказе реально работает несколько
     * администраторов сразу, делят начисление между собой поровну (доли тонко
     * настраиваются уже на уровне позиции, см. updateItemPayout). После ручного
     * выбора admin_assignment_mode переключается на 'manual' — автоназначение
     * по создателю заказа больше не должно перезаписывать выбор менеджера.
     */
    public function updateAdmin(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $employeeIds = $validated['employee_ids'] ?? [];

        $workOrder->admins()->sync($employeeIds);
        $workOrder->update(['admin_assignment_mode' => 'manual']);

        $labels = Employee::whereIn('id', $employeeIds)->get()->map(fn ($e) => trim($e->first_name.' '.$e->last_name));

        $adminChangeText = $labels->isNotEmpty()
            ? "Администраторы заказа №{$workOrder->id} изменены на «".$labels->implode('», «').'»'
            : "Администраторы заказа №{$workOrder->id} сняты";
        ActivityLogger::log($workOrder, $adminChangeText, $this->workOrderLink($workOrder), 'admin_changed');

        return redirect()->back()->with('success', 'Администраторы заказа обновлены');
    }

    /**
     * Распределение выплат по конкретной позиции-услуге (Фаза 10.1): кто на
     * ней администратор(-ы) (наследуют от заказа / свои / отсутствуют) и ручные
     * переопределения ставки/доли для каждого уже прикреплённого исполнителя.
     * Список самих исполнителей (кто прикреплён) правится отдельно, через
     * updateItem() — здесь только метаданные расчёта ЗП для уже прикреплённых.
     * Список администраторов позиции (work_order_item_admins), в отличие от
     * исполнителей, полностью управляется отсюда же (sync) — у него нет
     * отдельного мультиселекта в основной таблице позиций, только в этой форме.
     * Пивот НЕ очищается, если admin_override не 'custom' — предыдущий custom-
     * набор остаётся в БД про запас: при обратном переключении на 'custom'
     * форма подставит его снова, а не заставит выбирать заново.
     */
    public function updateItemPayout(Request $request, WorkOrder $workOrder, WorkOrderItem $item)
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(403);
        }

        $validated = $request->validate([
            'admin_override' => ['required', 'string', 'in:inherit,custom,none'],
            'admin_assignments' => ['array'],
            'admin_assignments.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'admin_assignments.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'admin_assignments.*.manual_amount_override' => ['nullable', 'numeric', 'min:0'],
            'admin_assignments.*.manual_percent_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'assignments' => ['array'],
            'assignments.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignments.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'assignments.*.manual_amount_override' => ['nullable', 'numeric', 'min:0'],
            'assignments.*.manual_percent_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Подрядчики правятся отдельным массивом: у них нет доли объёма
            // (share_percent к ним не применяется — см. PayrollCalculationService),
            // поэтому и валидируется у них только сумма/процент.
            'contractor_assignments' => ['array'],
            'contractor_assignments.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'contractor_assignments.*.manual_amount_override' => ['nullable', 'numeric', 'min:0'],
            'contractor_assignments.*.manual_percent_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $adminAssignments = $validated['admin_assignments'] ?? [];
        if ($validated['admin_override'] === 'custom' && empty($adminAssignments)) {
            return redirect()->back()->withErrors(['admin_assignments' => 'Выберите хотя бы одного администратора или смените режим.']);
        }

        $totalAdminShare = collect($adminAssignments)->sum(fn ($a) => $a['share_percent'] ?? 0);
        if ($totalAdminShare > 100.001) {
            return redirect()->back()->withErrors(['admin_assignments' => 'Суммарная доля администраторов позиции превышает 100%.']);
        }

        $assignments = $validated['assignments'] ?? [];
        $totalShare = collect($assignments)->sum(fn ($a) => $a['share_percent'] ?? 0);
        if ($totalShare > 100.001) {
            return redirect()->back()->withErrors(['assignments' => 'Суммарный объём работ бригады превышает 100%.']);
        }

        $contractorAssignments = $validated['contractor_assignments'] ?? [];

        DB::transaction(function () use ($item, $validated, $assignments, $adminAssignments, $contractorAssignments) {
            $item->update(['admin_override' => $validated['admin_override']]);

            if ($validated['admin_override'] === 'custom') {
                $adminSyncData = [];
                foreach ($adminAssignments as $assignment) {
                    $adminSyncData[$assignment['employee_id']] = [
                        'share_percent' => $assignment['share_percent'] ?? null,
                        'manual_amount_override' => isset($assignment['manual_amount_override']) ? (int) round($assignment['manual_amount_override'] * 100) : null,
                        'manual_percent_override' => $assignment['manual_percent_override'] ?? null,
                    ];
                }
                $item->admins()->sync($adminSyncData);
            }

            foreach ($assignments as $assignment) {
                $item->employees()->updateExistingPivot($assignment['employee_id'], [
                    'share_percent' => $assignment['share_percent'] ?? null,
                    'manual_amount_override' => isset($assignment['manual_amount_override']) ? (int) round($assignment['manual_amount_override'] * 100) : null,
                    'manual_percent_override' => $assignment['manual_percent_override'] ?? null,
                ]);
            }

            foreach ($contractorAssignments as $assignment) {
                $item->contractors()->updateExistingPivot($assignment['client_id'], [
                    'manual_amount_override' => isset($assignment['manual_amount_override']) ? (int) round($assignment['manual_amount_override'] * 100) : null,
                    'manual_percent_override' => $assignment['manual_percent_override'] ?? null,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Распределение выплат по позиции сохранено');
    }

    /**
     * Предварительный расчёт ЗП для вкладки "Зарплата" — считается заново
     * при каждом открытии вкладки (PayrollCalculationService::calculate()
     * дешёвый и детерминированный, кэш не используется намеренно: закешированный
     * предрасчёт мог бы разойтись с фактическим начислением при завершении
     * заказа, если между просмотром и завершением поменяли состав бригады,
     * цену, долю или ставку — а на реальных деньгах это недопустимо).
     * Не Inertia-рендер, а JSON — подгружается лениво по клику на вкладку.
     */
    public function payrollPreview(WorkOrder $workOrder)
    {
        $workOrder->load(['items.employees', 'items.contractors', 'items.itemable']);

        $result = PayrollCalculationService::calculate($workOrder);

        $employeeIds = collect($result['rows'])->pluck('employee_id')->filter()->unique();
        $clientIds = collect($result['rows'])->pluck('client_id')->filter()->unique();

        // withoutGlobalScope(BranchScope) — по той же причине, что и в самих
        // связях исполнителей (см. WorkOrderItem::employees()): получатель
        // начисления не должен превращаться в «—» из-за выбранной в шапке
        // локации. Расчёт его уже учёл, скрывать имя в предпросмотре нельзя.
        $employees = Employee::withoutGlobalScope(BranchScope::class)->withTrashed()
            ->whereIn('id', $employeeIds)->get(['id', 'first_name', 'last_name', 'type'])->keyBy('id');
        $clients = Client::withoutGlobalScope(BranchScope::class)->withTrashed()
            ->whereIn('id', $clientIds)->get(['id', 'name'])->keyBy('id');
        $items = $workOrder->items->keyBy('id');

        $payeeInfo = function (array $row) use ($employees, $clients) {
            if ($row['client_id']) {
                return [
                    'employee_id' => null,
                    'client_id' => $row['client_id'],
                    'name' => $clients[$row['client_id']]->name ?? '—',
                    'type' => 'contractor',
                ];
            }

            $id = $row['employee_id'];

            return [
                'employee_id' => $id,
                'client_id' => null,
                'name' => $employees->has($id) ? trim($employees[$id]->first_name.' '.$employees[$id]->last_name) : '—',
                'type' => $employees[$id]->type ?? null,
            ];
        };

        $byItem = collect($result['rows'])->groupBy('work_order_item_id')->map(function ($rows, $itemId) use ($items, $payeeInfo) {
            $admin = $rows->firstWhere('role', 'admin');
            $workers = $rows->where('role', 'worker')->values();

            return [
                'item_id' => (int) $itemId,
                'item_name' => $items->get((int) $itemId)?->name,
                'admin' => $admin ? array_merge($payeeInfo($admin), ['amount' => $admin['amount']]) : null,
                'workers' => $workers->map(fn ($w) => array_merge($payeeInfo($w), ['amount' => $w['amount']]))->all(),
            ];
        })->values();

        return response()->json([
            'items' => $byItem,
            'skipped' => $result['skipped'],
            'total' => collect($result['rows'])->sum('amount'),
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:work_orders,id'],
        ]);

        $this->unlinkAppointments($validated['ids']);
        WorkOrder::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные заказы удалены');
    }

    /**
     * Заказ-наряд удаляется мягко (SoftDeletes), поэтому ON DELETE SET NULL на
     * appointments.work_order_id не срабатывает (это чисто UPDATE deleted_at,
     * а не настоящий DELETE) — иначе в карточке записи оставалась бы ссылка на
     * уже удалённый заказ. Снимаем связь и статус явно, без учёта BranchScope
     * текущего пользователя — это чистка внутренней целостности данных, а не
     * выборка для отображения, она не должна зависеть от того, какая точка
     * сейчас выбран у того, кто удаляет заказ.
     */
    private function unlinkAppointments(array $workOrderIds): void
    {
        Appointment::withoutGlobalScope(BranchScope::class)
            ->whereIn('work_order_id', $workOrderIds)
            ->update(['work_order_id' => null, 'status' => 'scheduled']);
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:work_orders,id'],
        ]);

        ExportEntitiesJob::dispatch('work_orders', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }

    // --- УПРАВЛЕНИЕ ПОЗИЦИЯМИ (ITEMS) ---

    public function addItem(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'itemable_type' => ['required', 'string', 'in:service,product'],
            'itemable_id' => ['nullable', 'integer'],
            'is_custom' => ['boolean'],
            'save_to_catalog' => ['boolean'],
            'service_category_id' => ['nullable', 'exists:service_categories,id'],
            'business_direction_id' => ['nullable', 'exists:business_directions,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
            'assignee_type' => ['nullable', 'string', 'in:employee,contractor'],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->assertAssigneeIdsExist($validated['assignee_type'] ?? 'employee', $validated['employee_ids'] ?? []);

        $isCustom = $validated['is_custom'] ?? false;

        // Позиции без каталожной карточки допустимы только для услуг — товар без карточки
        // Product физически нечего списывать со склада (нет unit/accounting_type/остатков).
        if ($isCustom && $validated['itemable_type'] !== 'service') {
            return redirect()->back()->withErrors(['itemable_type' => 'Свою позицию без карточки можно добавить только как услугу.']);
        }

        if (! $isCustom && empty($validated['itemable_id'])) {
            return redirect()->back()->withErrors(['itemable_id' => 'Выберите позицию из каталога.']);
        }

        $priceCents = (int) round($validated['price'] * 100);
        $discountCents = (int) round(($validated['discount_amount'] ?? 0) * 100);
        $totalCents = max(0, (int) round($validated['quantity'] * $priceCents) - $discountCents);

        DB::transaction(function () use ($validated, $workOrder, $isCustom, $priceCents, $discountCents, $totalCents) {
            $itemableId = $validated['itemable_id'] ?? null;

            if ($isCustom && ! empty($validated['save_to_catalog'])) {
                $service = Service::create([
                    'service_category_id' => $validated['service_category_id'] ?? null,
                    'business_direction_id' => $validated['business_direction_id'] ?? null,
                    'name' => [app()->getLocale() => $validated['name']],
                    'price' => $priceCents,
                    'duration_minutes' => $validated['duration_minutes'] ?? 0,
                    'is_active' => true,
                ]);
                $itemableId = $service->id;
            }

            $nextSortOrder = (int) $workOrder->items()->max('sort_order') + 1;

            $item = $workOrder->items()->create([
                'itemable_type' => $validated['itemable_type'] === 'service' ? Service::class : Product::class,
                'itemable_id' => $itemableId,
                'name' => $validated['name'],
                'quantity' => $validated['quantity'],
                'price' => $priceCents,
                'discount_amount' => $discountCents,
                'total' => $totalCents,
                'sort_order' => $nextSortOrder,
            ]);

            if (! empty($validated['employee_ids'])) {
                // Позиция только что создана, чужих исполнителей на ней быть не
                // может — проверка на смешивание типов здесь не нужна (см.
                // assertAssigneeTypesNotMixed() в updateItem()).
                $this->assigneeRelation($item, $validated['assignee_type'] ?? 'employee')->sync($validated['employee_ids']);
            }

            ActivityLogger::log($workOrder, "Добавлена позиция «{$item->name}» в заказ №{$workOrder->id}", $this->workOrderLink($workOrder), 'item_added');
        });

        $this->recalculateTotals($workOrder);

        return redirect()->back()->with('success', 'Позиция добавлена');
    }

    public function updateItem(Request $request, WorkOrder $workOrder, WorkOrderItem $item)
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
            'assignee_type' => ['nullable', 'string', 'in:employee,contractor'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($request->has('employee_ids')) {
            $type = $validated['assignee_type'] ?? 'employee';
            $ids = $validated['employee_ids'] ?? [];

            $this->assertAssigneeIdsExist($type, $ids);

            if ($error = $this->assigneeTypesMixedError($item, $type, $ids)) {
                return redirect()->back()->withErrors(['employee_ids' => $error]);
            }

            $this->assigneeRelation($item, $type)->sync($ids);
        }

        $data = [];
        if ($request->has('quantity') || $request->has('price') || $request->has('discount_amount')) {
            $qty = $validated['quantity'] ?? $item->quantity;
            $price = $request->has('price') ? (int) round($validated['price'] * 100) : $item->price;
            $discount = $request->has('discount_amount') ? (int) round($validated['discount_amount'] * 100) : $item->discount_amount;

            $data['quantity'] = $qty;
            $data['price'] = $price;
            $data['discount_amount'] = $discount;
            $data['total'] = max(0, (int) round($qty * $price) - $discount);
        }

        if (! empty($data)) {
            $item->update($data);
        }

        $this->recalculateTotals($workOrder);

        return redirect()->back()->with('success', 'Позиция обновлена');
    }

    /**
     * Исполнителем позиции может быть штатный сотрудник ИЛИ подрядчик (Client
     * с ролью «Подрядчик») — обе связи ходят в одну полиморфную таблицу
     * work_order_item_assignees, но каждая видит и синхронизирует только свои
     * строки (withPivotValue попадает и в pivot-запрос sync()). Именно поэтому
     * запрет на смешивание нужен отдельной проверкой: сами по себе связи друг
     * другу не мешают и спокойно ужились бы на одной позиции.
     */
    private function assigneeRelation(WorkOrderItem $item, string $type)
    {
        return $type === 'contractor' ? $item->contractors() : $item->employees();
    }

    /**
     * Проверка существования вынесена из правил валидации, потому что таблица
     * зависит от типа исполнителя (employees/clients), а для подрядчика этого
     * мало: назначать можно только клиента, которому реально выдана роль
     * «Подрядчик» — иначе исполнителем услуги стал бы обычный заказчик.
     */
    private function assertAssigneeIdsExist(string $type, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        if ($type === 'contractor') {
            $found = Client::whereIn('id', $ids)
                ->whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::CONTRACTOR_ROLE))
                ->count();

            if ($found !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'Исполнителем можно назначить только клиента с ролью «'.self::CONTRACTOR_ROLE_LABEL.'».',
                ]);
            }

            return;
        }

        if (Employee::whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Выбран несуществующий сотрудник.',
            ]);
        }
    }

    /**
     * На одной позиции нельзя одновременно держать штатных исполнителей и
     * подрядчиков (разные схемы расчёта: бригада делит базу долями, подрядчик
     * получает свою сумму целиком). Возвращает текст ошибки или null.
     */
    private function assigneeTypesMixedError(WorkOrderItem $item, string $type, array $ids): ?string
    {
        if (empty($ids)) {
            return null;
        }

        $otherType = $type === 'contractor' ? 'employee' : 'contractor';

        if ($this->assigneeRelation($item, $otherType)->exists()) {
            return $type === 'contractor'
                ? 'На позиции уже назначены штатные исполнители. Уберите их, прежде чем назначать подрядчика — смешивать нельзя.'
                : 'На позиции уже назначен подрядчик. Уберите его, прежде чем назначать штатных исполнителей — смешивать нельзя.';
        }

        return null;
    }

    public function removeItem(WorkOrder $workOrder, WorkOrderItem $item)
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(403);
        }

        $itemName = $item->name;
        $item->delete();
        $this->recalculateTotals($workOrder);
        ActivityLogger::log($workOrder, "Удалена позиция «{$itemName}» из заказа №{$workOrder->id}", $this->workOrderLink($workOrder), 'item_removed');

        return redirect()->back()->with('success', 'Позиция удалена');
    }

    /**
     * Группирует позиции: сначала все услуги, затем все товары — относительный порядок
     * внутри каждой группы сохраняется (стабильная сортировка по текущему sort_order).
     */
    public function autoSortItems(WorkOrder $workOrder)
    {
        $items = $workOrder->items()->get();

        $ordered = $items->sortBy(function (WorkOrderItem $item) {
            return $item->itemable_type === Service::class ? 0 : 1;
        })->values();

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $index => $item) {
                $item->update(['sort_order' => $index]);
            }
        });

        return redirect()->back()->with('success', 'Позиции упорядочены: услуги сверху, товары снизу');
    }

    public function reorderItems(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:work_order_items,id'],
        ]);

        DB::transaction(function () use ($validated, $workOrder) {
            foreach ($validated['ids'] as $index => $id) {
                WorkOrderItem::where('id', $id)
                    ->where('work_order_id', $workOrder->id)
                    ->update(['sort_order' => $index]);
            }
        });

        return redirect()->back()->with('success', 'Порядок позиций обновлён');
    }

    public function updateDiscount(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'discount_amount' => ['required_if:auto,false', 'nullable', 'numeric', 'min:0'],
            'auto' => ['nullable', 'boolean'],
        ]);

        $auto = ! empty($validated['auto']);

        if ($auto) {
            // Возврат к автоматической скидке по грейду клиента — сама сумма
            // считается ниже в recalculateTotals(), здесь только снимаем флаг.
            $workOrder->discount_is_manual = false;
        } else {
            $workOrder->discount_amount = (int) round($validated['discount_amount'] * 100);
            $workOrder->discount_is_manual = true;
        }
        $workOrder->save();

        $this->recalculateTotals($workOrder);
        $message = $auto
            ? "Скидка по заказу №{$workOrder->id} возвращена на автоматическую (по грейду клиента)"
            : "Скидка по заказу №{$workOrder->id} изменена на ".$this->formatMoney($workOrder->discount_amount);
        ActivityLogger::log($workOrder, $message, $this->workOrderLink($workOrder), 'discount_updated');

        return redirect()->back()->with('success', 'Скидка обновлена');
    }

    // --- ФИНАНСЫ И СКЛАД (ФАЗА 8.3) ---

    public function processPayment(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $amountCents = (int) round($validated['amount'] * 100);

        // Фронтенд уже ограничивает поле суммы остатком долга (max на инпуте) —
        // это лишь UI-подсказка, реальную защиту от переплаты (случайная
        // опечатка в сумме, прямой вызов API в обход формы) даёт только
        // серверная проверка. Без неё оплата принималась на любую сумму:
        // payment_status просто становится 'paid' без следа о том, что часть
        // денег — переплата, без способа её потом отследить или вернуть.
        $paidSoFar = $workOrder->transactions()->where('type', 'income')->sum('amount');
        $remainingCents = max(0, $workOrder->final_amount - $paidSoFar);

        if ($amountCents > $remainingCents) {
            return redirect()->back()->withErrors(['amount' => 'Сумма оплаты ('.$this->formatMoney($amountCents).') превышает остаток долга по заказу ('.$this->formatMoney($remainingCents).').']);
        }

        // Оплата бонусами дополнительно не может превышать бонусный баланс клиента
        if ($account->type === 'bonus') {
            $rate = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
            $client = $workOrder->client;
            $maxBonusCents = $rate > 0 ? (int) floor($client->bonus_points * $rate * 100) : 0;

            if ($amountCents > $maxBonusCents) {
                return redirect()->back()->withErrors(['account_id' => 'Недостаточно бонусов у клиента для списания этой суммы.']);
            }
        }

        try {
            $pointsEarned = 0;

            DB::transaction(function () use ($workOrder, $amountCents, $account, &$pointsEarned) {
                FinanceService::processTransaction([
                    'account_id' => $account->id,
                    'branch_id' => $workOrder->branch_id,
                    'type' => 'income',
                    'amount' => $amountCents,
                    'comment' => 'Оплата по заказ-наряду #'.$workOrder->id,
                    'payable_type' => WorkOrder::class,
                    'payable_id' => $workOrder->id,
                ], auth()->id());

                // Списание бонусных баллов клиента пропорционально оплаченной сумме
                if ($account->type === 'bonus') {
                    $rate = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
                    $pointsUsed = $rate > 0 ? (int) round(($amountCents / 100) / $rate) : 0;
                    $client = $workOrder->client;
                    $pointsUsed = max(0, min($pointsUsed, $client->bonus_points));

                    if ($pointsUsed > 0) {
                        $client->decrement('bonus_points', $pointsUsed);
                    }
                } else {
                    // Начисление кэшбека (Фаза 14.1) — только с реально уплаченных денег,
                    // не с оплаты бонусами (иначе баллы начислялись бы сами на себя).
                    // Процент — из грейда клиента (ClientGroup.cashback_percent), курс
                    // конвертации рублей в баллы — тот же bonus_rub_per_point, что и при списании.
                    $client = $workOrder->client;
                    $cashbackPercent = (float) ($client->group->cashback_percent ?? 0);

                    if ($cashbackPercent > 0) {
                        $rate = (float) (Setting::where('key', 'bonus_rub_per_point')->value('value') ?? 1);
                        $cashbackRub = ($amountCents / 100) * $cashbackPercent / 100;
                        $pointsEarned = $rate > 0 ? (int) floor($cashbackRub / $rate) : 0;

                        if ($pointsEarned > 0) {
                            $client->increment('bonus_points', $pointsEarned);
                        }
                    }
                }

                // Комиссия эквайринга: отдельная расходная транзакция с того же счета.
                // Заказ считается оплаченным на валовую сумму (комиссия не уменьшает погашенный долг клиента).
                if ($account->type === 'acquiring' && $account->commission_percent > 0) {
                    $commissionCents = (int) round($amountCents * $account->commission_percent / 100);

                    if ($commissionCents > 0) {
                        FinanceService::processTransaction([
                            'account_id' => $account->id,
                            'branch_id' => $workOrder->branch_id,
                            'type' => 'expense',
                            'amount' => $commissionCents,
                            'comment' => 'Комиссия эквайринга по заказ-наряду #'.$workOrder->id,
                            'payable_type' => WorkOrder::class,
                            'payable_id' => $workOrder->id,
                        ], auth()->id());
                    }
                }

                $workOrder->syncPaymentStatus();
                $paymentMessage = 'Принята оплата '.$this->formatMoney($amountCents)." по заказу №{$workOrder->id} ({$account->name})";
                if ($pointsEarned > 0) {
                    $paymentMessage .= ", начислено {$pointsEarned} бонусных баллов";
                }
                ActivityLogger::log($workOrder, $paymentMessage, $this->workOrderLink($workOrder), 'payment_received');
            });

            // Переоценка грейда клиента — вне транзакции оплаты (не финансовая
            // операция, откатывать её вместе с платежом не нужно).
            LoyaltyGradeService::evaluate($workOrder->client);

            return redirect()->back()->with('success', 'Оплата успешно принята');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оплате: '.$e->getMessage()]);
        }
    }

    public function completeOrder(Request $request, WorkOrder $workOrder)
    {
        if ($workOrder->status === 'completed') {
            return redirect()->back()->withErrors(['status' => 'Заказ уже завершен']);
        }

        try {
            $stockTouched = false;

            DB::transaction(function () use ($workOrder, &$stockTouched) {
                $branch = $workOrder->branch;

                // Склад отключён тумблером (Настройки → Склад) — товар остаётся
                // просто позицией заказа, списания не происходит вовсе (не
                // просто пропускается лог, а физически не создаётся StockMovement).
                if (WarehouseResolver::isEnabled()) {
                    foreach ($workOrder->items as $item) {
                        if ($item->itemable_type === Product::class) {
                            $product = $item->itemable;
                            if (! $product) {
                                continue;
                            }

                            $warehouse = WarehouseResolver::resolveFor($product, $branch);

                            if (! $warehouse) {
                                $productName = is_array($product->name) ? ($product->name['ru'] ?? current($product->name)) : $product->name;
                                throw new Exception("Не удалось определить склад для списания товара: {$productName}");
                            }

                            StockService::deduct(
                                $product,
                                $warehouse,
                                $branch->id,
                                $item->quantity,
                                $workOrder->id,
                                auth()->id()
                            );
                            $stockTouched = true;
                        }
                    }
                }

                $workOrder->update(['status' => 'completed']);
                $completionMessage = $stockTouched
                    ? "Заказ №{$workOrder->id} завершён, материалы списаны со склада"
                    : "Заказ №{$workOrder->id} завершён";
                ActivityLogger::log($workOrder, $completionMessage, $this->workOrderLink($workOrder), 'completed');

                // Фаза 10.2: автоматическое начисление ЗП по факту завершения
                // заказа. Не блокирует завершение, если для кого-то из
                // назначенных не настроена ставка — такие начисления
                // пропускаются с пояснением в "Истории", а не превращаются
                // в блокер для менеджера, который просто хочет закрыть заказ.
                $payroll = PayrollCalculationService::calculate($workOrder);

                foreach ($payroll['rows'] as $row) {
                    Payroll::create([
                        // Ровно одно из двух заполнено — начисление либо штатному
                        // сотруднику, либо подрядчику (Client с ролью «Подрядчик»).
                        'employee_id' => $row['employee_id'],
                        'client_id' => $row['client_id'],
                        'branch_id' => $workOrder->branch_id,
                        'work_order_id' => $workOrder->id,
                        'work_order_item_id' => $row['work_order_item_id'],
                        'type' => 'accrual',
                        'role' => $row['role'],
                        'amount' => $row['amount'],
                        'status' => 'pending',
                        'created_by' => auth()->id(),
                    ]);
                }

                if (! empty($payroll['rows'])) {
                    ActivityLogger::log($workOrder, "По заказу №{$workOrder->id} начислена зарплата по ".count($payroll['rows']).' позициям', $this->workOrderLink($workOrder), 'payroll_accrued');
                }

                if (! empty($payroll['skipped'])) {
                    ActivityLogger::log($workOrder, "По заказу №{$workOrder->id} не начислена ЗП для части исполнителей — не настроена ставка:\n".implode("\n", $payroll['skipped']), $this->workOrderLink($workOrder), 'payroll_skipped');
                }
            });

            $successMessage = $stockTouched
                ? 'Заказ успешно завершен, материалы списаны со склада'
                : 'Заказ успешно завершен';

            return redirect()->back()->with('success', $successMessage);
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // --- QUICK ADD FROM WORK ORDER ---
    // storeServiceQuick удалён: его функциональность (быстрое создание услуги из карточки заказа)
    // полностью поглощена addItem() с флагами is_custom + save_to_catalog — теперь одним шагом
    // позиция сразу добавляется в заказ, а не только создаётся в каталоге отдельно.

    public function storeProductQuick(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'accounting_type' => ['required', 'string', 'in:average,batch'],
        ]);

        Product::create([
            'product_category_id' => $validated['product_category_id'],
            'name' => [app()->getLocale() => $validated['name']],
            'sku' => $validated['sku'],
            'unit' => $validated['unit'],
            'accounting_type' => $validated['accounting_type'],
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Товар быстро добавлен в справочник');
    }

    private function workOrderStatuses()
    {
        return Lookup::where('type', 'work_order_status')
            ->orderBy('sort_order')
            ->get(['id', 'value', 'label', 'color', 'is_active', 'is_system']);
    }

    private function activeStatusValues(): array
    {
        return Lookup::where('type', 'work_order_status')
            ->where('is_active', true)
            ->pluck('value')
            ->all();
    }

    private function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' ₽';
    }

    /**
     * Ссылка на заказ для properties.links — событие, залогированное на WorkOrder,
     * может показываться через roll-up в чужой ленте (Клиент/Автомобиль), где
     * непонятно, о каком именно заказе речь без явной ссылки на первоисточник.
     *
     * @return array<int, array{type: string, id: int, label: string}>
     */
    private function workOrderLink(WorkOrder $workOrder): array
    {
        return [
            ['type' => 'work_order', 'id' => $workOrder->id, 'label' => "Заказ №{$workOrder->id}"],
        ];
    }

    private function recalculateTotals(WorkOrder $workOrder)
    {
        $total = $workOrder->items()->sum('total');

        if ($workOrder->discount_is_manual) {
            $discount = $workOrder->discount_amount;
        } else {
            // Автоматическая скидка по грейду клиента (LoyaltyGradeService) —
            // пересчитывается с нуля от текущей суммы заказа при каждом
            // изменении состава позиций, пока сотрудник не задаст скидку
            // вручную (updateDiscount() ставит discount_is_manual=true).
            $discountPercent = $workOrder->client ? LoyaltyGradeService::resolveDiscountPercent($workOrder->client) : 0;
            $discount = (int) round($total * $discountPercent / 100);
        }

        if ($discount > $total) {
            $discount = $total;
        }

        $netAfterDiscount = $total - $discount;

        // НДС — снепшот ставки/принципа с юрлица заказа В МОМЕНТ пересчёта
        // (не читается заново при каждом открытии, см. CLAUDE.md — тот же
        // принцип, что у Document::isStale(), "зафиксированный во времени
        // артефакт"). Резолюция ЮРЛИЦА — та же, что и у печатных документов
        // (DocumentPlaceholderService::resolveLegalEntity()), иначе расчёт
        // НДС и то, что покажет документ, могли бы разойтись.
        $legalEntity = DocumentPlaceholderService::resolveLegalEntity($workOrder);
        $vatRate = null;
        $vatMethod = null;
        $vatAmount = 0;
        $final = $netAfterDiscount;

        if ($legalEntity && $legalEntity->vat_payer) {
            $vatRate = $legalEntity->vat_rate;
            $vatMethod = $legalEntity->vat_calculation_method;

            if ($vatMethod === 'exclusive') {
                // Позиции набирались БЕЗ НДС — налог начисляется сверх уже
                // посчитанной суммы, final_amount увеличивается.
                $vatAmount = (int) round($netAfterDiscount * $vatRate / 100);
                $final = $netAfterDiscount + $vatAmount;
            } else {
                // inclusive (по умолчанию) — сумма позиций УЖЕ включает
                // НДС, просто выделяем налог отдельной строкой; final_amount
                // не меняется — та же формула, что и без НДС вовсе.
                $vatAmount = (int) round($netAfterDiscount * $vatRate / (100 + $vatRate));
            }
        }

        $workOrder->update([
            'total_amount' => $total,
            'discount_amount' => $discount,
            'final_amount' => $final,
            'vat_rate' => $vatRate,
            'vat_calculation_method' => $vatMethod,
            'vat_amount' => $vatAmount,
        ]);

        $workOrder->syncPaymentStatus();
    }

    private function saveCustomFields(WorkOrder $workOrder, array $customFieldsData)
    {
        foreach ($customFieldsData as $key => $value) {
            $def = CustomFieldDefinition::where('entity_type', 'work_order')->where('key', $key)->first();

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
                        'entity_type' => 'work_order',
                        'entity_id' => $workOrder->id,
                    ],
                    $valData
                );
            }
        }
    }
}

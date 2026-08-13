<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ListView;
use App\Models\Lookup;
use App\Models\Post;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\WorkOrder;
use App\Services\ActivityLogger;
use App\Services\QueryFilterService;
use App\Services\TimezoneResolver;
use App\Services\WorkingHoursResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    /**
     * Значение системной роли клиента (тот же принцип и то же
     * value-значение, что и WorkOrderController::CONTRACTOR_ROLE — стабильный
     * слаг, не завязанный на отображаемый текст роли в Справочниках).
     */
    private const CONTRACTOR_ROLE = 'contractor';

    private const CONTRACTOR_ROLE_LABEL = 'Подрядчик';

    public function index(Request $request): Response
    {
        $query = Appointment::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'employee', 'contractor', 'post', 'items']);

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['comment']
        );

        if (! $request->has('sort_by')) {
            $query->orderBy('start_at', 'desc');
        }

        $appointments = $query->paginate(15)->withQueryString();

        // start_at/end_at — время по часовому поясу точки (wall-clock), а не UTC-метка аудита.
        // Добавляем локальные представления для отображения и для предзаполнения формы редактирования.
        $appointments->getCollection()->transform(function (Appointment $appointment) {
            $tz = TimezoneResolver::forBranch($appointment->branch_id);
            $appointment->setAttribute('start_at_local', $appointment->start_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'));
            $appointment->setAttribute('end_at_local', $appointment->end_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'));
            $appointment->setAttribute('start_at_display', $appointment->start_at->copy()->setTimezone($tz)->format('d.m.Y H:i'));
            $appointment->setAttribute('end_at_display', $appointment->end_at->copy()->setTimezone($tz)->format('d.m.Y H:i'));

            return $appointment;
        });

        $branches = Branch::forSelect()->get(['id', 'name', 'working_hours']);
        $clients = Client::orderBy('name')->get(['id', 'name', 'phone']);
        $vehicles = Vehicle::with(['make', 'vehicleModel'])->get(['id', 'client_id', 'vehicle_make_id', 'vehicle_model_id', 'plate_number']);
        $employees = Employee::where('is_active', true)->get(['id', 'first_name', 'last_name']);
        $contractors = $this->contractorOptions();
        $posts = Post::where('is_active', true)->orderBy('sort_order')->get(['id', 'branch_id', 'name', 'icon']);
        $services = Service::where('is_active', true)->get(['id', 'name', 'price']);
        $products = Product::where('is_active', true)->get(['id', 'name']);
        $makes = VehicleMake::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $models = VehicleModel::where('is_active', true)->orderBy('name')->get(['id', 'vehicle_make_id', 'name']);

        $availableColumns = [
            ['key' => 'start_at', 'label' => 'Время записи'],
            ['key' => 'client', 'label' => 'Клиент'],
            ['key' => 'vehicle', 'label' => 'Автомобиль'],
            ['key' => 'branch', 'label' => 'Локация'],
            ['key' => 'employee', 'label' => 'Мастер'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'comment', 'label' => 'Комментарий'],
        ];

        $listView = ListView::where('entity_type', 'appointment')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        // Настраиваемый состав и порядок полей карточки записи в календаре
        // (Неделя/День-слева/День-сверху) и всплывающей подсказки — тот же
        // механизм list_views, что и у колонок таблицы выше, только с другим
        // entity_type. Настраивается на странице Записей, кнопкой рядом с
        // переключателем видов календаря.
        $calendarFieldOptions = [
            ['key' => 'time', 'label' => 'Время'],
            ['key' => 'client', 'label' => 'Клиент'],
            ['key' => 'vehicle', 'label' => 'Автомобиль'],
            ['key' => 'phone', 'label' => 'Телефон'],
            ['key' => 'branch', 'label' => 'Локация'],
            ['key' => 'employee', 'label' => 'Мастер'],
            ['key' => 'post', 'label' => 'Пост'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'comment', 'label' => 'Комментарий'],
        ];
        $cardListView = ListView::where('entity_type', 'appointment_calendar_card')->where('user_id', auth()->id())->first();
        $tooltipListView = ListView::where('entity_type', 'appointment_calendar_tooltip')->where('user_id', auth()->id())->first();

        // Ссылка "Открыть запись" (например, из карточки заказ-наряда) ведёт сюда
        // с ?appointment=ID — запись может не попасть в текущую страницу/фильтр
        // списка, поэтому подгружаем её отдельно и открываем модалку на фронте.
        $openAppointment = null;
        if ($request->filled('appointment')) {
            $openAppointment = Appointment::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'employee', 'contractor', 'post', 'items'])
                ->find($request->query('appointment'));

            if ($openAppointment) {
                $tz = TimezoneResolver::forBranch($openAppointment->branch_id);
                $openAppointment->setAttribute('start_at_local', $openAppointment->start_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'));
                $openAppointment->setAttribute('end_at_local', $openAppointment->end_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'));
            }
        }

        return Inertia::render('Operations/Appointments/Index', [
            'appointments' => $appointments,
            'filters' => $request->all(),
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'employees' => $employees,
            'contractors' => $contractors,
            'posts' => $posts,
            'services' => $services,
            'products' => $products,
            'makes' => $makes,
            'models' => $models,
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
            'appointmentStatuses' => $this->appointmentStatuses(),
            'defaultWorkingHours' => WorkingHoursResolver::forTenant(),
            'calendarFieldOptions' => $calendarFieldOptions,
            'calendarCardFields' => $cardListView->visible_columns ?? ['time', 'client', 'vehicle', 'phone'],
            'calendarTooltipFields' => $tooltipListView->visible_columns ?? ['time', 'client', 'vehicle', 'phone', 'branch', 'employee', 'post', 'comment'],
            'openAppointment' => $openAppointment,
        ]);
    }

    /**
     * JSON-фид для FullCalendar (Фаза 9.1). Возвращает записи, пересекающие видимый
     * диапазон календаря, с временем уже переведённым в пояс точки (виджет
     * настроен на timeZone:'local' — отображает переданные строки как есть,
     * без повторной конвертации браузером). Диапазон запроса намеренно расширен
     * на сутки в обе стороны — FullCalendar присылает границы без метки часового
     * пояса, а сами записи могут быть в разных поясах у разных точек тенанта.
     */
    public function calendarEvents(Request $request)
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
        ]);

        $rangeStart = Carbon::parse($validated['start'])->subDay();
        $rangeEnd = Carbon::parse($validated['end'])->addDay();

        $appointments = Appointment::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'employee', 'contractor', 'post', 'items'])
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->get();

        $statusColors = Lookup::where('type', 'appointment_status')->pluck('color', 'value');
        // Те же hex, что и в resources/js/tailwind.config.js — раньше здесь был
        // произвольный набор цветов, визуально не совпадающий с остальной темой CRM.
        $colorMap = [
            'info' => '#16a7e9', 'primary' => '#3e60d5', 'success' => '#47ad77',
            'danger' => '#f15776', 'warning' => '#ffc35a', 'gray' => '#6c757d',
        ];
        // Источник цвета записи настраивается в Настройки → CRM: по статусу (по
        // умолчанию), по исполнителю или по посту — см. Employee/Post.calendar_color.
        // Если у выбранного источника цвет не задан, используем цвет по статусу.
        $colorSource = Setting::where('key', 'calendar_color_source')->value('value') ?? 'status';

        $events = $appointments->map(function (Appointment $appointment) use ($statusColors, $colorMap, $colorSource) {
            $tz = TimezoneResolver::forBranch($appointment->branch_id);
            $startLocal = $appointment->start_at->copy()->setTimezone($tz);
            $endLocal = $appointment->end_at->copy()->setTimezone($tz);
            $statusColor = $colorMap[$statusColors[$appointment->status] ?? 'gray'] ?? '#9ca3af';
            $color = match ($colorSource) {
                'employee' => $appointment->employee?->calendar_color ?: $statusColor,
                'post' => $appointment->post?->calendar_color ?: $statusColor,
                default => $statusColor,
            };

            $title = $appointment->client?->name ?: 'Без клиента';
            if ($appointment->vehicle?->plate_number) {
                $title .= ' — '.$appointment->vehicle->plate_number;
            }

            return [
                'id' => $appointment->id,
                'title' => $title,
                'start' => $startLocal->format('Y-m-d\TH:i:s'),
                'end' => $endLocal->format('Y-m-d\TH:i:s'),
                'color' => $color,
                'extendedProps' => [
                    'appointment' => [
                        'id' => $appointment->id,
                        'branch_id' => $appointment->branch_id,
                        'client_id' => $appointment->client_id,
                        'vehicle_id' => $appointment->vehicle_id,
                        'employee_id' => $appointment->employee_id,
                        'contractor_id' => $appointment->contractor_id,
                        'post_id' => $appointment->post_id,
                        'status' => $appointment->status,
                        'work_order_id' => $appointment->work_order_id,
                        'comment' => $appointment->comment,
                        'start_at_local' => $startLocal->format('Y-m-d\TH:i'),
                        'end_at_local' => $endLocal->format('Y-m-d\TH:i'),
                        'items' => $appointment->items->map(fn ($item) => [
                            'itemable_type' => $item->itemable_type,
                            'itemable_id' => $item->itemable_id,
                            'name' => $item->name,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                        ]),
                    ],
                ],
            ];
        });

        return response()->json($events);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAppointment($request);
        $branchTz = TimezoneResolver::forBranch($validated['branch_id']);
        $startAtUtc = Carbon::parse($validated['start_at'], $branchTz)->utc();
        $endAtUtc = Carbon::parse($validated['end_at'], $branchTz)->utc();

        $this->assertEndWithinWorkingDay($validated['branch_id'], $endAtUtc, $branchTz);
        $this->assertNoOverlap($validated['post_id'] ?? null, $startAtUtc, $endAtUtc);

        DB::transaction(function () use ($validated, $startAtUtc, $endAtUtc) {
            $appointment = Appointment::create([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'contractor_id' => $validated['contractor_id'] ?? null,
                'post_id' => $validated['post_id'] ?? null,
                'start_at' => $startAtUtc,
                'end_at' => $endAtUtc,
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
            ]);

            $this->syncItems($appointment, $validated['items'] ?? []);
        });

        return redirect()->back()->with('success', 'Запись успешно создана');
    }

    public function update(Request $request, Appointment $appointment)
    {
        if ($appointment->status === 'converted') {
            return redirect()->back()->withErrors(['error' => 'Запись уже конвертирована в заказ-наряд и недоступна для правки.']);
        }

        $validated = $this->validateAppointment($request, $appointment);
        $branchTz = TimezoneResolver::forBranch($validated['branch_id']);
        $startAtUtc = Carbon::parse($validated['start_at'], $branchTz)->utc();
        $endAtUtc = Carbon::parse($validated['end_at'], $branchTz)->utc();

        $this->assertEndWithinWorkingDay($validated['branch_id'], $endAtUtc, $branchTz);
        $this->assertNoOverlap($validated['post_id'] ?? null, $startAtUtc, $endAtUtc, $appointment->id);

        DB::transaction(function () use ($validated, $appointment, $startAtUtc, $endAtUtc) {
            $appointment->update([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'contractor_id' => $validated['contractor_id'] ?? null,
                'post_id' => $validated['post_id'] ?? null,
                'start_at' => $startAtUtc,
                'end_at' => $endAtUtc,
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
            ]);

            if (isset($validated['items'])) {
                $this->syncItems($appointment, $validated['items']);
            }
        });

        return redirect()->back()->with('success', 'Запись обновлена');
    }

    /**
     * Окончание записи не может выпадать на нерабочий день точки (например,
     * воскресенье, если оно закрыто в расписании) — часы работы берутся из
     * WorkingHoursResolver (точка -> фолбэк на тенант, тот же принцип, что и
     * у TimezoneResolver). Проверяем только end_at (не start_at) — по
     * буквальной постановке задачи: начать обслуживание можно вечером
     * рабочего дня, а вот "закончить" запись в день, когда точка закрыта,
     * бессмысленно. Расписание не задано нигде (ни у точки, ни у тенанта) —
     * ограничений нет, WorkingHoursResolver::forBranch() вернёт null.
     */
    private function assertEndWithinWorkingDay(int $branchId, Carbon $endAtUtc, string $branchTz): void
    {
        $hours = WorkingHoursResolver::forBranch($branchId);
        if (empty($hours)) {
            return;
        }

        $dayLabels = [
            'mon' => 'понедельник', 'tue' => 'вторник', 'wed' => 'среда',
            'thu' => 'четверг', 'fri' => 'пятница', 'sat' => 'суббота', 'sun' => 'воскресенье',
        ];
        $dayKeys = array_keys($dayLabels);
        $endLocal = $endAtUtc->copy()->setTimezone($branchTz);
        $dayKey = $dayKeys[$endLocal->dayOfWeekIso - 1];

        $daySchedule = collect($hours)->firstWhere('day', $dayKey);

        if ($daySchedule && ! ($daySchedule['is_open'] ?? true)) {
            throw ValidationException::withMessages([
                'end_at' => 'Локация не работает в этот день ('.$dayLabels[$dayKey].') — окончание записи нельзя ставить на нерабочий день. Выберите другое время.',
            ]);
        }
    }

    /**
     * Проверка пересечения по времени включена только для постов с
     * Post::prevent_overlapping_appointments = true — по умолчанию посты
     * это разрешают (например когда фактическая параллельная работа возможна).
     */
    private function assertNoOverlap(?int $postId, Carbon $startAtUtc, Carbon $endAtUtc, ?int $excludeAppointmentId = null): void
    {
        if (! $postId) {
            return;
        }

        $post = Post::find($postId);
        if (! $post || ! $post->prevent_overlapping_appointments) {
            return;
        }

        $overlaps = Appointment::where('post_id', $postId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeAppointmentId, fn ($query) => $query->where('id', '!=', $excludeAppointmentId))
            ->where('start_at', '<', $endAtUtc)
            ->where('end_at', '>', $startAtUtc)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'post_id' => 'На этот пост уже есть запись, пересекающаяся по времени. Выберите другое время или другой пост.',
            ]);
        }
    }

    public function destroy(Appointment $appointment)
    {
        // Админ может удалить запись в любом статусе (см. CLAUDE.md, п. 6 —
        // "Право администратора на удаление без ограничений") — например, это
        // единственный способ убрать зависшую запись со статусом "converted",
        // чей заказ-наряд был удалён ещё до внедрения автоматической отвязки.
        if ($appointment->status === 'converted' && ! auth()->user()->isAdmin()) {
            return redirect()->back()->withErrors(['error' => 'Запись уже конвертирована в заказ-наряд и не может быть удалена.']);
        }

        $appointment->delete();

        return redirect()->back()->with('success', 'Запись удалена');
    }

    /**
     * Фаза 9.4: бесшовная конвертация записи в заказ-наряд по факту приезда
     * клиента. AppointmentItem копируются в WorkOrderItem как стартовые
     * позиции (совместимый формат: itemable_type уже хранится полным именем
     * класса, price — в копейках — как и у WorkOrderItem). Склад и финансы
     * запись не затрагивала и не затрагивает при конвертации — WorkOrder
     * создаётся в статусе "new", списание материалов произойдёт только при
     * его последующем завершении (WorkOrderController::completeOrder()).
     */
    public function convertToWorkOrder(Appointment $appointment)
    {
        if ($appointment->status === 'converted' || $appointment->work_order_id) {
            return redirect()->back()->withErrors(['error' => 'Запись уже оформлена в заказ-наряд.']);
        }

        if (in_array($appointment->status, ['cancelled', 'no_show'], true)) {
            return redirect()->back()->withErrors(['error' => 'Отменённую запись или запись со статусом "Не пришёл" нельзя оформить в заказ-наряд.']);
        }

        $workOrder = DB::transaction(function () use ($appointment) {
            $workOrder = WorkOrder::create([
                'branch_id' => $appointment->branch_id,
                'client_id' => $appointment->client_id,
                'vehicle_id' => $appointment->vehicle_id,
                'status' => 'new',
                'payment_status' => 'unpaid',
                'total_amount' => 0,
                'discount_amount' => 0,
                'final_amount' => 0,
            ]);

            $sortOrder = 0;
            foreach ($appointment->items as $item) {
                $workOrderItem = $workOrder->items()->create([
                    'itemable_type' => $item->itemable_type,
                    'itemable_id' => $item->itemable_id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount_amount' => 0,
                    'total' => (int) round($item->quantity * $item->price),
                    'sort_order' => $sortOrder++,
                ]);

                if ($appointment->employee_id) {
                    $workOrderItem->employees()->sync([$appointment->employee_id]);
                } elseif ($appointment->contractor_id) {
                    $workOrderItem->contractors()->sync([$appointment->contractor_id]);
                }
            }

            $total = $workOrder->items()->sum('total');
            $workOrder->update(['total_amount' => $total, 'final_amount' => $total]);
            $workOrder->syncPaymentStatus();

            $appointment->update([
                'work_order_id' => $workOrder->id,
                'status' => 'converted',
            ]);

            $appointmentDate = $this->appointmentDateLabel($appointment);

            ActivityLogger::log($workOrder, "Заказ-наряд №{$workOrder->id} создан из записи в календаре на {$appointmentDate}", [
                ['type' => 'appointment', 'id' => $appointment->id, 'label' => "Запись на {$appointmentDate}"],
            ], 'created');
            ActivityLogger::log($appointment, "Запись на {$appointmentDate} оформлена в заказ-наряд №{$workOrder->id}", [
                ['type' => 'work_order', 'id' => $workOrder->id, 'label' => "Заказ №{$workOrder->id}"],
            ], 'appointment_linked');

            return $workOrder;
        });

        return redirect()->route('operations.work-orders.show', $workOrder)->with('success', 'Запись оформлена в заказ-наряд');
    }

    /**
     * Фаза 9.4: привязка записи к уже существующему заказ-наряду — когда заказ
     * создан напрямую (не через конвертацию), а не наоборот. В отличие от
     * convertToWorkOrder(), позиции сметы записи НЕ копируются в заказ — у него
     * уже могут быть свои позиции, отражающие фактически выполненные работы,
     * дублировать их автоматически было бы неверно. Только простановка связи.
     */
    public function linkWorkOrder(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'work_order_id' => ['required', 'exists:work_orders,id'],
        ]);

        if ($appointment->status === 'converted' || $appointment->work_order_id) {
            return redirect()->back()->withErrors(['error' => 'Запись уже привязана к заказ-наряду.']);
        }

        if (in_array($appointment->status, ['cancelled', 'no_show'], true)) {
            return redirect()->back()->withErrors(['error' => 'Отменённую запись или запись со статусом "Не пришёл" нельзя привязать к заказ-наряду.']);
        }

        $workOrder = WorkOrder::find($validated['work_order_id']);

        if (! $workOrder || $workOrder->client_id !== $appointment->client_id) {
            return redirect()->back()->withErrors(['error' => 'Заказ-наряд принадлежит другому клиенту.']);
        }

        $appointment->update([
            'work_order_id' => $workOrder->id,
            'status' => 'converted',
        ]);

        $appointmentDate = $this->appointmentDateLabel($appointment);

        ActivityLogger::log($workOrder, "Запись на {$appointmentDate} из календаря привязана к заказу №{$workOrder->id}", [
            ['type' => 'appointment', 'id' => $appointment->id, 'label' => "Запись на {$appointmentDate}"],
        ], 'appointment_linked');
        ActivityLogger::log($appointment, "Запись на {$appointmentDate} привязана к заказ-наряду №{$workOrder->id}", [
            ['type' => 'work_order', 'id' => $workOrder->id, 'label' => "Заказ №{$workOrder->id}"],
        ], 'appointment_linked');

        return redirect()->back()->with('success', 'Запись привязана к заказ-наряду');
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        if ($appointment->status === 'converted') {
            return redirect()->back()->withErrors(['status' => 'Запись уже конвертирована в заказ-наряд, статус нельзя менять напрямую.']);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
        ]);

        if ($validated['status'] === 'converted') {
            return redirect()->back()->withErrors(['status' => 'Статус "converted" выставляется только конвертацией в заказ-наряд.']);
        }

        $appointment->update(['status' => $validated['status']]);

        return redirect()->back()->with('success', 'Статус обновлён');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:appointments,id'],
        ]);

        $query = Appointment::whereIn('id', $validated['ids']);
        if (! auth()->user()->isAdmin()) {
            $query->where('status', '!=', 'converted');
        }
        $query->delete();

        return redirect()->back()->with('success', 'Выбранные записи удалены');
    }

    private function validateAppointment(Request $request, ?Appointment $appointment = null): array
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'contractor_id' => ['nullable', 'exists:clients,id', $this->contractorRoleRule()],
            'post_id' => ['nullable', 'exists:posts,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['required', 'string', Rule::in($this->activeStatusValues())],
            'comment' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.itemable_type' => ['required_with:items', 'string', 'in:service,product'],
            'items.*.itemable_id' => ['required_with:items', 'integer'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        // Исполнитель записи — либо штатный сотрудник, либо подрядчик, не оба
        // сразу (тот же принцип "нельзя смешивать", что и у исполнителей
        // позиции заказа, см. WorkOrderController::assigneeTypesMixedError —
        // здесь проще: не коллекция, а максимум один исполнитель на запись).
        if (! empty($validated['employee_id']) && ! empty($validated['contractor_id'])) {
            throw ValidationException::withMessages([
                'contractor_id' => 'Исполнителем записи может быть либо штатный сотрудник, либо подрядчик — не оба одновременно.',
            ]);
        }

        return $validated;
    }

    /**
     * Исполнителем-подрядчиком можно назначить только клиента с ролью
     * «Подрядчик» — тот же принцип, что и у WorkOrderController::
     * assertAssigneeIdsExist() для исполнителей позиции заказа.
     */
    private function contractorRoleRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (! $value) {
                return;
            }

            $isContractor = Client::where('id', $value)
                ->whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::CONTRACTOR_ROLE))
                ->exists();

            if (! $isContractor) {
                $fail('Исполнителем-подрядчиком можно назначить только клиента с ролью «'.self::CONTRACTOR_ROLE_LABEL.'».');
            }
        };
    }

    private function contractorOptions()
    {
        return Client::whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::CONTRACTOR_ROLE))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);
    }

    private function syncItems(Appointment $appointment, array $items): void
    {
        $appointment->items()->delete();

        foreach ($items as $item) {
            $appointment->items()->create([
                'itemable_type' => $item['itemable_type'] === 'service' ? Service::class : Product::class,
                'itemable_id' => $item['itemable_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => (int) round($item['price'] * 100),
            ]);
        }
    }

    private function appointmentStatuses()
    {
        return Lookup::where('type', 'appointment_status')
            ->orderBy('sort_order')
            ->get(['id', 'value', 'label', 'color', 'is_active', 'is_system']);
    }

    private function activeStatusValues(): array
    {
        return Lookup::where('type', 'appointment_status')
            ->where('is_active', true)
            ->pluck('value')
            ->all();
    }

    /**
     * Дата записи в локальном времени точки — для точного текста события в
     * Истории (см. CLAUDE.md §7): по roll-up событие видно и на карточке
     * Клиента/Автомобиля, где без даты непонятно, о какой именно записи речь.
     */
    private function appointmentDateLabel(Appointment $appointment): string
    {
        $tz = TimezoneResolver::forBranch($appointment->branch_id);

        return $appointment->start_at->copy()->setTimezone($tz)->format('d.m.Y H:i');
    }
}

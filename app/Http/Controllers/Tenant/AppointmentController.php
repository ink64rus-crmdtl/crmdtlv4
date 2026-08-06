<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Post;
use App\Models\Service;
use App\Models\Product;
use App\Models\Lookup;
use App\Models\ListView;
use App\Models\Setting;
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
    public function index(Request $request): Response
    {
        $query = Appointment::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'employee', 'post', 'items']);

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            ['comment']
        );

        if (!$request->has('sort_by')) {
            $query->orderBy('start_at', 'desc');
        }

        $appointments = $query->paginate(15)->withQueryString();

        // start_at/end_at — время по часовому поясу филиала (wall-clock), а не UTC-метка аудита.
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
        $posts = Post::where('is_active', true)->orderBy('sort_order')->get(['id', 'branch_id', 'name', 'icon']);
        $services = Service::where('is_active', true)->get(['id', 'name', 'price']);
        $products = Product::where('is_active', true)->get(['id', 'name']);

        $availableColumns = [
            ['key' => 'start_at', 'label' => 'Время записи'],
            ['key' => 'client', 'label' => 'Клиент'],
            ['key' => 'vehicle', 'label' => 'Автомобиль'],
            ['key' => 'branch', 'label' => 'Филиал'],
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
            ['key' => 'branch', 'label' => 'Филиал'],
            ['key' => 'employee', 'label' => 'Мастер'],
            ['key' => 'post', 'label' => 'Пост'],
            ['key' => 'status', 'label' => 'Статус'],
            ['key' => 'comment', 'label' => 'Комментарий'],
        ];
        $cardListView = ListView::where('entity_type', 'appointment_calendar_card')->where('user_id', auth()->id())->first();
        $tooltipListView = ListView::where('entity_type', 'appointment_calendar_tooltip')->where('user_id', auth()->id())->first();

        return Inertia::render('Operations/Appointments/Index', [
            'appointments' => $appointments,
            'filters' => $request->all(),
            'branches' => $branches,
            'clients' => $clients,
            'vehicles' => $vehicles,
            'employees' => $employees,
            'posts' => $posts,
            'services' => $services,
            'products' => $products,
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
            'appointmentStatuses' => $this->appointmentStatuses(),
            'defaultWorkingHours' => WorkingHoursResolver::forTenant(),
            'calendarFieldOptions' => $calendarFieldOptions,
            'calendarCardFields' => $cardListView->visible_columns ?? ['time', 'client', 'vehicle', 'phone'],
            'calendarTooltipFields' => $tooltipListView->visible_columns ?? ['time', 'client', 'vehicle', 'phone', 'branch', 'employee', 'post', 'comment'],
        ]);
    }

    /**
     * JSON-фид для FullCalendar (Фаза 9.1). Возвращает записи, пересекающие видимый
     * диапазон календаря, с временем уже переведённым в пояс филиала (виджет
     * настроен на timeZone:'local' — отображает переданные строки как есть,
     * без повторной конвертации браузером). Диапазон запроса намеренно расширен
     * на сутки в обе стороны — FullCalendar присылает границы без метки часового
     * пояса, а сами записи могут быть в разных поясах у разных филиалов тенанта.
     */
    public function calendarEvents(Request $request)
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
        ]);

        $rangeStart = Carbon::parse($validated['start'])->subDay();
        $rangeEnd = Carbon::parse($validated['end'])->addDay();

        $appointments = Appointment::with(['branch', 'client', 'vehicle.make', 'vehicle.vehicleModel', 'employee', 'post', 'items'])
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
                $title .= ' — ' . $appointment->vehicle->plate_number;
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
                        'post_id' => $appointment->post_id,
                        'status' => $appointment->status,
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

        $this->assertNoOverlap($validated['post_id'] ?? null, $startAtUtc, $endAtUtc);

        DB::transaction(function () use ($validated, $startAtUtc, $endAtUtc) {
            $appointment = Appointment::create([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
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

        $this->assertNoOverlap($validated['post_id'] ?? null, $startAtUtc, $endAtUtc, $appointment->id);

        DB::transaction(function () use ($validated, $appointment, $startAtUtc, $endAtUtc) {
            $appointment->update([
                'branch_id' => $validated['branch_id'],
                'client_id' => $validated['client_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
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
     * Проверка пересечения по времени включена только для постов с
     * Post::prevent_overlapping_appointments = true — по умолчанию посты
     * это разрешают (например когда фактическая параллельная работа возможна).
     */
    private function assertNoOverlap(?int $postId, Carbon $startAtUtc, Carbon $endAtUtc, ?int $excludeAppointmentId = null): void
    {
        if (!$postId) {
            return;
        }

        $post = Post::find($postId);
        if (!$post || !$post->prevent_overlapping_appointments) {
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
        if ($appointment->status === 'converted') {
            return redirect()->back()->withErrors(['error' => 'Запись уже конвертирована в заказ-наряд и не может быть удалена.']);
        }

        $appointment->delete();

        return redirect()->back()->with('success', 'Запись удалена');
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

        Appointment::whereIn('id', $validated['ids'])->where('status', '!=', 'converted')->delete();

        return redirect()->back()->with('success', 'Выбранные записи удалены');
    }

    private function validateAppointment(Request $request, ?Appointment $appointment = null): array
    {
        return $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
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
}

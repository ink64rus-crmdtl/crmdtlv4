<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lookup;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Фаза 17 — сделки и канбан-доска.
 *
 * Канбан НЕ грузит все сделки разом: каждая колонка отдаёт первые
 * self::CARDS_PER_COLUMN карточек, остальные подтягиваются по кнопке
 * (loadStage()), а итоги по колонке считаются АГРЕГАТОМ в SQL, а не
 * суммированием загруженных карточек. Иначе доска ложится на большой базе
 * и нарушается запрет ->get() для списков (CLAUDE.md §6).
 */
class DealController extends Controller
{
    /** Сколько карточек отдаём в колонку сразу. Остальные — по кнопке «Показать ещё». */
    private const CARDS_PER_COLUMN = 20;

    public function index(Request $request): Response
    {
        $pipelines = Pipeline::where('is_active', true)
            ->with('businessDirection:id,name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'business_direction_id', 'is_default']);

        $pipeline = $this->resolvePipeline($request, $pipelines);

        if (! $pipeline) {
            // Ни одной воронки — доску строить не из чего. Отдаём пустое
            // состояние с приглашением настроить, а не 404: тенант мог просто
            // ещё не дойти до настроек.
            return Inertia::render('Sales/Deals/Index', [
                'pipelines' => $pipelines,
                'pipeline' => null,
                'columns' => [],
                'filters' => $request->all(),
                'owners' => [],
                'leadsWithoutDeals' => 0,
            ]);
        }

        $stages = $pipeline->stages()->where('is_active', true)->get();

        $columns = $stages->map(fn (PipelineStage $stage) => $this->buildColumn($stage, $request))->all();

        return Inertia::render('Sales/Deals/Index', [
            'pipelines' => $pipelines,
            'pipeline' => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'business_direction' => $pipeline->businessDirection?->only(['id', 'name']),
            ],
            'columns' => $columns,
            'filters' => $request->only(['owner_user_id', 'search', 'only_rotting']),
            'owners' => User::orderBy('name')->get(['id', 'name']),
            // Нужны прямо на доске: перетаскивание в «Проигрыш» открывает
            // модалку с обязательным выбором причины, до перехода на Карточку.
            'lossReasons' => Lookup::where('type', 'deal_loss_reason')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'label']),
            // Подсказка вместо автоконвертации: лиды, по которым ещё не завели
            // ни одной сделки (CLAUDE.md, Фаза 17 — is_lead намеренно остаётся).
            'leadsWithoutDeals' => Client::where('is_lead', true)
                ->whereDoesntHave('deals')
                ->count(),
        ]);
    }

    /**
     * Догрузка следующей страницы карточек ОДНОЙ колонки — доска не
     * перерисовывается целиком, остальные колонки не трогаются.
     */
    public function loadStage(Request $request, PipelineStage $stage)
    {
        return response()->json($this->buildColumn($stage, $request, (int) $request->input('page', 2)));
    }

    public function show(Deal $deal): Response
    {
        $deal->load([
            'branch' => fn ($q) => $q->withTrashed(),
            'legalEntity' => fn ($q) => $q->withTrashed(),
            'client',
            'vehicle.make',
            'vehicle.vehicleModel',
            'pipeline.businessDirection',
            'stage',
            'owner',
            'source',
            'lossReason',
            'appointment',
            'workOrder',
            'items',
        ]);

        $feed = ActivityLogger::present(ActivityLogger::feedFor($deal));

        return Inertia::render('Sales/Deals/Show', [
            'deal' => $deal,
            'isRotting' => $deal->isRotting(),
            'isAbandoned' => $deal->isAbandoned(),
            'stages' => $deal->pipeline->stages()->where('is_active', true)->get(),
            'pipelines' => Pipeline::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'owners' => User::orderBy('name')->get(['id', 'name']),
            'lossReasons' => Lookup::where('type', 'deal_loss_reason')->where('is_active', true)->orderBy('sort_order')->get(['id', 'label']),
            'taskTypes' => Lookup::where('type', 'task_type')->where('is_active', true)->orderBy('sort_order')->get(['id', 'value', 'label']),
            'activities' => $feed['activities'],
            'comments' => $feed['comments'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateDeal($request);

        $stage = $this->resolveInitialStage($validated['pipeline_id']);

        if (! $stage) {
            return redirect()->back()->withErrors(['pipeline_id' => 'У выбранной воронки нет ни одной активной стадии — настройте её в Настройки → Воронки.']);
        }

        $deal = DB::transaction(function () use ($validated, $stage) {
            $deal = Deal::create([
                ...$validated,
                'pipeline_stage_id' => $stage->id,
                'stage_entered_at' => now(),
                'created_by' => auth()->id(),
            ]);

            ActivityLogger::log(
                $deal,
                "Сделка «{$deal->title}» создана на стадии «{$stage->name}»",
                $this->dealLink($deal),
                'created'
            );

            return $deal;
        });

        return redirect()->route('sales.deals.show', $deal)->with('success', 'Сделка создана');
    }

    public function update(Request $request, Deal $deal)
    {
        $validated = $this->validateDeal($request, $deal);

        $deal->update($validated);

        ActivityLogger::log($deal, "Сделка «{$deal->title}» изменена", $this->dealLink($deal), 'updated');

        return redirect()->back()->with('success', 'Сделка обновлена');
    }

    /**
     * Перенос сделки в другую стадию — и drag-n-drop на доске, и select
     * на Карточке ходят сюда же, отдельного кода для перетаскивания нет.
     */
    public function moveStage(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'pipeline_stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'loss_reason_lookup_id' => ['nullable', 'integer', 'exists:lookups,id'],
        ]);

        $stage = PipelineStage::find($validated['pipeline_stage_id']);

        // Стадия обязана принадлежать воронке этой сделки — иначе через
        // подделанный запрос сделка уехала бы в чужую воронку.
        if ($stage->pipeline_id !== $deal->pipeline_id) {
            return redirect()->back()->withErrors(['pipeline_stage_id' => 'Стадия принадлежит другой воронке.']);
        }

        if ($stage->id === $deal->pipeline_stage_id) {
            return redirect()->back();
        }

        // Причина проигрыша обязательна — иначе через квартал невозможно
        // ответить на вопрос «почему теряем сделки» (приём лидеров рынка).
        if ($stage->type === PipelineStage::TYPE_LOST && empty($validated['loss_reason_lookup_id'])) {
            return redirect()->back()->withErrors(['loss_reason_lookup_id' => 'Укажите причину проигрыша — без неё сделку нельзя перевести в эту стадию.']);
        }

        $previous = $deal->stage?->name ?? '—';

        $deal->update([
            'pipeline_stage_id' => $stage->id,
            'stage_entered_at' => now(),
            'closed_at' => $stage->isClosing() ? now() : null,
            'loss_reason_lookup_id' => $stage->type === PipelineStage::TYPE_LOST
                ? $validated['loss_reason_lookup_id']
                : null,
        ]);

        ActivityLogger::log(
            $deal,
            "Сделка «{$deal->title}» перемещена со стадии «{$previous}» на «{$stage->name}»",
            $this->dealLink($deal),
            'stage_changed'
        );

        return redirect()->back()->with('success', 'Стадия обновлена');
    }

    /**
     * Конвертация в заказ-наряд. Паттерн скопирован с проверенного
     * AppointmentController::convertToWorkOrder() — включая двусторонние
     * записи в историю со ссылками друг на друга.
     */
    public function convertToWorkOrder(Deal $deal)
    {
        if ($deal->work_order_id) {
            return redirect()->back()->withErrors(['error' => 'По этой сделке уже оформлен заказ-наряд.']);
        }

        $workOrder = DB::transaction(function () use ($deal) {
            $workOrder = WorkOrder::create([
                'branch_id' => $deal->branch_id,
                'legal_entity_id' => $deal->legal_entity_id,
                'client_id' => $deal->client_id,
                'vehicle_id' => $deal->vehicle_id,
                'status' => 'new',
                'payment_status' => 'unpaid',
                'total_amount' => 0,
                'discount_amount' => 0,
                'final_amount' => 0,
                'created_by' => auth()->id(),
            ]);

            $sortOrder = 0;
            foreach ($deal->items as $item) {
                $workOrder->items()->create([
                    'itemable_type' => $item->itemable_type,
                    'itemable_id' => $item->itemable_id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount_amount' => 0,
                    'total' => (int) round($item->quantity * $item->price),
                    'sort_order' => $sortOrder++,
                ]);
            }

            $total = $workOrder->items()->sum('total');
            $workOrder->update(['total_amount' => $total, 'final_amount' => $total]);
            $workOrder->syncPaymentStatus();

            $deal->update(['work_order_id' => $workOrder->id]);

            ActivityLogger::log(
                $workOrder,
                "Заказ-наряд №{$workOrder->id} создан из сделки «{$deal->title}»",
                [['type' => 'deal', 'id' => $deal->id, 'label' => "Сделка «{$deal->title}»"]],
                'created'
            );
            ActivityLogger::log(
                $deal,
                "По сделке «{$deal->title}» оформлен заказ-наряд №{$workOrder->id}",
                [['type' => 'work_order', 'id' => $workOrder->id, 'label' => "Заказ №{$workOrder->id}"]],
                'work_order_linked'
            );

            return $workOrder;
        });

        return redirect()->route('operations.work-orders.show', $workOrder)->with('success', 'Сделка оформлена в заказ-наряд');
    }

    public function addComment(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        ActivityLogger::log($deal, $validated['body'], [], 'comment');

        return redirect()->back();
    }

    public function destroy(Deal $deal)
    {
        // Сделка не влияет ни на склад, ни на финансы (см. докблок модели),
        // поэтому её удаление безопасно и не требует отката сервисов.
        // Но если по ней уже оформлен заказ — это workflow-статус, и здесь
        // действует §8: обход разрешён администратору.
        if ($deal->work_order_id && ! auth()->user()->isAdmin()) {
            return redirect()->back()->withErrors(['error' => 'По сделке оформлен заказ-наряд — удалить её может только администратор.']);
        }

        $deal->delete();

        return redirect()->route('sales.deals.index')->with('success', 'Сделка удалена');
    }

    // --- ВНУТРЕННЕЕ ---

    /**
     * Одна колонка доски: агрегаты по ВСЕЙ стадии (SQL) + страница карточек.
     *
     * @return array<string, mixed>
     */
    private function buildColumn(PipelineStage $stage, Request $request, int $page = 1): array
    {
        $base = fn () => $this->filteredDeals($stage, $request);

        // Итоги — агрегатами, независимо от того, сколько карточек показано.
        $totals = $base()
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(amount), 0) as amount_sum')
            ->first();

        $cards = $base()
            ->with(['client:id,name,phone', 'vehicle:id,vehicle_make_id,plate_number', 'vehicle.make:id,name', 'owner:id,name', 'stage:id,rotting_days,type'])
            // «Брошена» — открытая сделка без единой незавершённой задачи
            // (Deal::isAbandoned()). Считаем EXISTS-подзапросом в самой
            // выборке карточек, а не Deal::isAbandoned() построчно — тот бы
            // на каждую карточку страницы дал отдельный запрос (N+1).
            ->withExists(['tasks as has_open_task' => fn ($q) => $q->whereNull('completed_at')])
            ->orderByDesc('id')
            ->paginate(self::CARDS_PER_COLUMN, ['*'], 'page', $page);

        return [
            'stage' => [
                'id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color,
                'type' => $stage->type,
                'probability' => $stage->probability,
                'rotting_days' => $stage->rotting_days,
            ],
            'deals_count' => (int) $totals->deals_count,
            'amount_sum' => (int) $totals->amount_sum,
            // Взвешенный прогноз (приём HubSpot): сумма × вероятность стадии.
            'weighted_sum' => (int) round($totals->amount_sum * $stage->probability / 100),
            'has_more' => $cards->hasMorePages(),
            'current_page' => $cards->currentPage(),
            'deals' => $cards->getCollection()->map(fn (Deal $deal) => [
                'id' => $deal->id,
                'title' => $deal->title,
                'amount' => $deal->amount,
                'client' => $deal->client?->only(['id', 'name', 'phone']),
                'vehicle' => $deal->vehicle ? [
                    'id' => $deal->vehicle->id,
                    'label' => trim(($deal->vehicle->make?->name ?? '').' '.$deal->vehicle->plate_number),
                ] : null,
                'owner' => $deal->owner?->only(['id', 'name']),
                'expected_close_date' => $deal->expected_close_date?->format('Y-m-d'),
                'is_rotting' => $deal->isRotting(),
                'is_abandoned' => ! $stage->isClosing() && ! $deal->has_open_task,
            ])->values(),
        ];
    }

    /** Общий фильтр колонки — одинаковый для агрегатов и для карточек. */
    private function filteredDeals(PipelineStage $stage, Request $request)
    {
        $query = Deal::where('pipeline_stage_id', $stage->id);

        if ($owner = $request->input('owner_user_id')) {
            $query->where('owner_user_id', $owner);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        // «Протухшие» — фильтр по дате входа в стадию, считается в SQL,
        // а не перебором загруженных карточек (иначе фильтр врал бы за пределами страницы).
        if ($request->boolean('only_rotting') && $stage->rotting_days && ! $stage->isClosing()) {
            $query->where('stage_entered_at', '<', now()->subDays($stage->rotting_days));
        }

        return $query;
    }

    private function resolvePipeline(Request $request, $pipelines): ?Pipeline
    {
        $requested = $request->input('pipeline_id');

        $id = $requested && $pipelines->contains('id', (int) $requested)
            ? (int) $requested
            : ($pipelines->firstWhere('is_default', true)?->id ?? $pipelines->first()?->id);

        return $id ? Pipeline::with('businessDirection')->find($id) : null;
    }

    private function resolveInitialStage(int $pipelineId): ?PipelineStage
    {
        return PipelineStage::where('pipeline_id', $pipelineId)
            ->where('is_active', true)
            ->where('type', PipelineStage::TYPE_OPEN)
            ->orderBy('sort_order')
            ->first();
    }

    private function validateDeal(Request $request, ?Deal $deal = null): array
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'source_lookup_id' => ['nullable', 'integer', 'exists:lookups,id'],
        ]);

        // Юрлицо обязано реально входить в выбранную локацию — та же серверная
        // проверка, что и у WorkOrderController (CLAUDE.md §4), не только фронт.
        if (! empty($validated['legal_entity_id'])) {
            $belongs = Branch::where('id', $validated['branch_id'])
                ->whereHas('legalEntities', fn ($q) => $q->where('legal_entities.id', $validated['legal_entity_id']))
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'legal_entity_id' => 'Выбранное юрлицо не привязано к этой локации.',
                ]);
            }
        }

        // Автомобиль обязан принадлежать выбранному клиенту — иначе в сделке
        // окажется чужая машина, и она же уедет в заказ-наряд при конвертации.
        if (! empty($validated['vehicle_id'])) {
            $owns = Vehicle::where('id', $validated['vehicle_id'])
                ->where('client_id', $validated['client_id'])
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Этот автомобиль принадлежит другому клиенту.',
                ]);
            }
        }

        $validated['amount'] = (int) round(($validated['amount'] ?? 0) * 100);

        // pipeline_id при обновлении не даём менять «на лету»: смена воронки
        // означает и смену набора стадий, это отдельное осознанное действие.
        if ($deal) {
            unset($validated['pipeline_id']);
        }

        return $validated;
    }

    /**
     * @return array<int, array{type: string, id: int, label: string}>
     */
    private function dealLink(Deal $deal): array
    {
        return [
            ['type' => 'deal', 'id' => $deal->id, 'label' => "Сделка «{$deal->title}»"],
        ];
    }
}

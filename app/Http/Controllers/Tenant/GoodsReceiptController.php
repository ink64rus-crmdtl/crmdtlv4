<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DocumentTemplate;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Lookup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\FinanceService;
use App\Services\QueryFilterService;
use App\Services\StockService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Приходные накладные (Фаза "Поставщики" — см. CLAUDE.md). Заменяет прежнее
 * прямое оприходование через StockMovementController::storeReceipt() —
 * теперь позиции объединены под накладной с поставщиком, а не разрозненные
 * StockMovement без общего контекста. Список и Карточка — Tri-State Record
 * Pattern (см. CLAUDE.md), эталон — WorkOrderController.
 */
class GoodsReceiptController extends Controller
{
    /**
     * Значение системной роли клиента (см. WorkOrderController::CONTRACTOR_ROLE
     * — та же логика: стабильный value-слаг, а не отображаемый текст, поэтому
     * переименование роли в Справочниках это сравнение не ломает).
     */
    private const SUPPLIER_ROLE = 'supplier';

    private const SUPPLIER_ROLE_LABEL = 'Поставщик';

    public function index(Request $request): Response
    {
        $query = GoodsReceipt::with(['supplier:id,name,phone', 'warehouse:id,name', 'branch:id,name'])
            ->withCount('items')
            // Сумма закупки и оплаченное — подзапросами (не через ->items,
            // иначе на страницу из 15 накладных пришлось бы тянуть все их
            // позиции/транзакции целиком ради одной суммы). Тот же приём,
            // что withSum() у WorkOrderController::index() для paid_amount.
            ->addSelect($this->debtSubqueries());

        $query = QueryFilterService::apply($query, $request->all(), ['supplier_document_number', 'supplier.name'], allowedSorts: ['receipt_date', 'status', 'payment_status']);

        if ($request->filled('filters.payment_status')) {
            $query->where('payment_status', $request->input('filters.payment_status'));
        }

        if (! $request->has('sort_by')) {
            $query->orderBy('receipt_date', 'desc')->orderBy('id', 'desc');
        }

        // Сводка по долгу — с учётом уже применённых фильтров (поставщик/
        // склад/статус и т.п.), НЕ только по текущей странице пагинации:
        // повторный лёгкий запрос по уже отфильтрованному набору, до paginate().
        $debtSummary = (clone $query)->get(['id', 'payment_status', 'items_total', 'paid_total'])
            ->reduce(function (array $carry, GoodsReceipt $r) {
                $remaining = max(0, (int) $r->items_total - (int) $r->paid_total);
                $carry['total_debt'] += $remaining;
                $carry['receipts_with_debt'] += $remaining > 0 ? 1 : 0;

                return $carry;
            }, ['total_debt' => 0, 'receipts_with_debt' => 0]);

        $receipts = $query->paginate(15)->withQueryString();

        return Inertia::render('Warehouse/GoodsReceipts/Index', [
            'receipts' => $receipts,
            'debtSummary' => $debtSummary,
            'filters' => $request->all(),
            'suppliers' => $this->supplierOptions(),
            // Для формы быстрого добавления поставщика (crm.clients.store,
            // тот же приём, что и "+ клиент" в WorkOrders/Index.vue) — новому
            // клиенту сразу нужна роль «Поставщик», иначе он не попадёт в
            // supplierOptions() и его придётся донастраивать отдельно.
            'supplierRoleId' => Lookup::where('type', 'client_role')->where('value', self::SUPPLIER_ROLE)->value('id'),
            // Для CompanySuggestInput в модалке быстрого добавления поставщика —
            // DaData знает только российские организации (см. CRM/Clients,
            // Settings/LegalEntities — тот же принцип).
            'tenantCountry' => config('tenant.country_code', 'RU'),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name']),
            'branches' => Branch::forSelect()->with('legalEntities:id,name')->get(['id', 'name']),
            'products' => Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'accounting_type']),
            // Для модалки быстрого добавления товара (см. CompanySuggestInput-
            // аналог для товаров — переиспользуем существующий
            // WorkOrderController::storeProductQuick(), отдельный эндпоинт не нужен).
            'productCategories' => ProductCategory::where('is_active', true)->get(['id', 'name']),
            // Для иконки "Принять оплату" прямо в списке (см. show() ниже —
            // тот же набор счетов, без 'bonus': поставщику им не платят).
            'accounts' => auth()->user()->availableAccounts()->where('is_active', true)->where('type', '!=', 'bonus')->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.commission_percent']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateReceipt($request);

        try {
            $receipt = StockService::receiveGoods($validated, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оприходовании: '.$e->getMessage()]);
        }

        $receipt->load('warehouse:id,name');
        ActivityLogger::log($receipt, "Приходная накладная №{$receipt->id} создана — товар оприходован на склад «{$receipt->warehouse->name}»", [], 'created');

        return redirect()->route('warehouse.goods-receipts.show', $receipt->id)->with('success', 'Товары оприходованы, накладная создана');
    }

    public function show(GoodsReceipt $receipt): Response
    {
        $receipt->load([
            'supplier',
            'warehouse',
            'branch' => fn ($q) => $q->withTrashed(),
            'legalEntity' => fn ($q) => $q->withTrashed(),
            'items.product',
            'items.batch',
            'transactions.account',
            'documents' => fn ($q) => $q->with(['documentable', 'branch.legalEntities', 'supersededBy:id,number'])->orderBy('id', 'desc'),
        ])->append('total_value');
        $receipt->documents->each->append('is_stale');

        $presented = ActivityLogger::present(ActivityLogger::feedFor($receipt));

        return Inertia::render('Warehouse/GoodsReceipts/Show', [
            'receipt' => $receipt,
            'activities' => $presented['activities'],
            // Для добавления/редактирования позиций и quick-add товара прямо
            // с Карточки — тот же набор, что и на списке (index()).
            'products' => Product::where('is_active', true)->get(['id', 'name', 'sku', 'unit', 'accounting_type']),
            'productCategories' => ProductCategory::where('is_active', true)->get(['id', 'name']),
            // Для оплаты поставщику — тот же набор счетов, доступных
            // пользователю по ABAC (User::availableAccounts()), что и у
            // WorkOrderController::show(). 'bonus' исключён: виртуальный
            // счёт для списания баллов клиента, поставщику им не платят.
            'accounts' => auth()->user()->availableAccounts()->where('is_active', true)->where('type', '!=', 'bonus')->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.commission_percent']),
            'documentTemplates' => DocumentTemplate::where('entity_type', 'goods_receipt')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    /**
     * Оплата поставщику — тот же приём, что и WorkOrderController::
     * processPayment() (частичная оплата долга, сумма ограничена остатком
     * серверно, не только на фронте) и PayrollController::payout() (деньги
     * трогает ТОЛЬКО FinanceService::processTransaction(), никогда не сам
     * контроллер). type='expense' — деньги уходят из кассы поставщику,
     * в отличие от 'income' у оплаты клиентом заказ-наряда.
     */
    public function pay(Request $request, GoodsReceipt $receipt)
    {
        if ($receipt->status !== 'posted') {
            return redirect()->back()->withErrors(['error' => 'Накладная отменена — оплата недоступна.']);
        }

        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $amountCents = (int) round($validated['amount'] * 100);

        $receipt->loadMissing('items');
        $paidSoFar = $receipt->transactions()->where('type', 'expense')->sum('amount');
        $remainingCents = max(0, $receipt->total_value - $paidSoFar);

        if ($amountCents > $remainingCents) {
            return redirect()->back()->withErrors(['amount' => 'Сумма оплаты ('.$this->formatMoney($amountCents).') превышает остаток долга по накладной ('.$this->formatMoney($remainingCents).').']);
        }

        try {
            DB::transaction(function () use ($receipt, $account, $amountCents) {
                FinanceService::processTransaction([
                    'account_id' => $account->id,
                    'branch_id' => $receipt->branch_id,
                    'type' => 'expense',
                    'amount' => $amountCents,
                    'comment' => 'Оплата поставщику по накладной №'.$receipt->id.($receipt->supplier ? ' ('.$receipt->supplier->name.')' : ''),
                    'payable_type' => GoodsReceipt::class,
                    'payable_id' => $receipt->id,
                ], auth()->id());

                $receipt->syncPaymentStatus();
            });
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при оплате: '.$e->getMessage()]);
        }

        ActivityLogger::log($receipt, 'Оплата поставщику по накладной №'.$receipt->id.' на сумму '.$this->formatMoney($amountCents), [], 'payment');

        return redirect()->back()->with('success', 'Оплата проведена');
    }

    private function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' ₽';
    }

    /**
     * Добавление позиции к уже существующей накладной — StockService::
     * addReceiptItem() (блокируется для отменённой накладной).
     */
    public function addItem(Request $request, GoodsReceipt $receipt)
    {
        $itemData = $this->validateItem($request);

        try {
            StockService::addReceiptItem($receipt, $itemData, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "В накладную №{$receipt->id} добавлена позиция", [], 'item_added');

        return redirect()->back()->with('success', 'Позиция добавлена');
    }

    /**
     * Правка количества/цены/партии/товара уже существующей позиции —
     * StockService::updateReceiptItem() (реверс старого движения + повторное
     * оприходование новыми значениями; блокируется, если товар с этой
     * позиции уже частично списан — та же защита, что и у отмены накладной).
     */
    public function updateItem(Request $request, GoodsReceipt $receipt, GoodsReceiptItem $item)
    {
        if ($item->goods_receipt_id !== $receipt->id) {
            abort(403);
        }

        $itemData = $this->validateItem($request);

        try {
            StockService::updateReceiptItem($item, $itemData, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "В накладной №{$receipt->id} изменена позиция", [], 'item_updated');

        return redirect()->back()->with('success', 'Позиция обновлена');
    }

    /**
     * Удаление одной позиции — StockService::removeReceiptItem() (реверс
     * движения/остатка/партии, сама строка удаляется; блокируется, если
     * товар с этой позиции уже частично списан).
     */
    public function destroyItem(GoodsReceipt $receipt, GoodsReceiptItem $item)
    {
        if ($item->goods_receipt_id !== $receipt->id) {
            abort(403);
        }

        try {
            StockService::removeReceiptItem($item, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "Из накладной №{$receipt->id} удалена позиция", [], 'item_removed');

        return redirect()->back()->with('success', 'Позиция удалена');
    }

    /**
     * Отмена — реверс движений/остатков/партий (StockService::reverseReceipt()),
     * блокируется, если товар с поставки уже частично списан. Не удаление
     * записи: накладная остаётся в списке со статусом "Отменена".
     */
    public function cancel(GoodsReceipt $receipt)
    {
        try {
            StockService::reverseReceipt($receipt, auth()->id());
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        ActivityLogger::log($receipt, "Приход по накладной №{$receipt->id} отменён — движения реверсированы", [], 'canceled');

        return redirect()->back()->with('success', 'Накладная отменена');
    }

    private function supplierOptions()
    {
        return Client::whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::SUPPLIER_ROLE))
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);
    }

    /**
     * Сумма закупки (goods_receipt_items.quantity*cost_price) и оплаченное
     * (expense-транзакции payable=GoodsReceipt) — подзапросами-алиасами для
     * addSelect(), см. index()/debts(). Общий помощник, чтобы формула суммы
     * закупки не разъехалась между списком накладных и сводкой по поставщикам.
     */
    private function debtSubqueries(): array
    {
        return [
            'items_total' => GoodsReceiptItem::selectRaw('COALESCE(SUM(quantity * cost_price), 0)')
                ->whereColumn('goods_receipt_id', 'goods_receipts.id'),
            'paid_total' => Transaction::selectRaw('COALESCE(SUM(amount), 0)')
                ->where('payable_type', GoodsReceipt::class)
                ->where('type', 'expense')
                ->whereColumn('payable_id', 'goods_receipts.id'),
        ];
    }

    /**
     * Задолженность поставщикам (см. CLAUDE.md) — тот же архитектурный
     * паттерн, что и PayrollController::index()/contractorSettlements():
     * список строится ОТ накладных (агрегат по supplier_id), а не от всех
     * клиентов с ролью «Поставщик» — так список не разрастается вместе с
     * клиентской базой и не нуждается в пагинации. Отменённые накладные
     * (status=canceled) в долг не входят — реверсированный приход не создаёт
     * обязательства перед поставщиком.
     */
    public function debts(): Response
    {
        $sums = GoodsReceipt::where('goods_receipts.status', 'posted')
            ->join('goods_receipt_items', 'goods_receipt_items.goods_receipt_id', '=', 'goods_receipts.id')
            ->groupBy('goods_receipts.supplier_id')
            ->selectRaw('goods_receipts.supplier_id, COUNT(DISTINCT goods_receipts.id) as receipts_count, SUM(goods_receipt_items.quantity * goods_receipt_items.cost_price) as accrued_total')
            ->get();

        if ($sums->isEmpty()) {
            return Inertia::render('Warehouse/SuppliersDebt/Index', ['suppliers' => []]);
        }

        $paidSums = Transaction::where('payable_type', GoodsReceipt::class)
            ->where('type', 'expense')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'transactions.payable_id')
            ->whereIn('goods_receipts.supplier_id', $sums->pluck('supplier_id'))
            ->groupBy('goods_receipts.supplier_id')
            ->selectRaw('goods_receipts.supplier_id, SUM(transactions.amount) as paid_total')
            ->get()
            ->keyBy('supplier_id');

        // withTrashed — задолженность перед поставщиком, чья карточка была
        // удалена, не должна тихо пропадать из сводки (тот же принцип, что
        // и у PayrollController::contractorSettlements()).
        $suppliers = Client::withTrashed()
            ->whereIn('id', $sums->pluck('supplier_id'))
            ->get(['id', 'name', 'phone', 'deleted_at'])
            ->keyBy('id');

        $rows = $sums->map(function ($row) use ($paidSums, $suppliers) {
            $accruedTotal = (int) $row->accrued_total;
            $paidTotal = (int) ($paidSums->get($row->supplier_id)->paid_total ?? 0);
            $supplier = $suppliers->get($row->supplier_id);

            return [
                'id' => $row->supplier_id,
                'name' => $supplier?->name ?? '—',
                'phone' => $supplier?->phone,
                'is_deleted' => (bool) $supplier?->deleted_at,
                'receipts_count' => (int) $row->receipts_count,
                'accrued_total' => $accruedTotal,
                'paid_total' => $paidTotal,
                'balance' => $accruedTotal - $paidTotal,
            ];
        })->sortByDesc('balance')->values()->all();

        return Inertia::render('Warehouse/SuppliersDebt/Index', ['suppliers' => $rows]);
    }

    /**
     * Юрлицо накладной обязано реально входить в legalEntities() выбранной
     * локации — тот же паттерн, что и у WorkOrderController::
     * legalEntityBelongsToBranchRule() (защита от подмены запроса напрямую,
     * фронт и так фильтрует список).
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

    /**
     * @return array{product_id:int, quantity:float, cost_price:int, batch_number:?string}
     */
    private function validateItem(Request $request): array
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:255'],
        ]);

        // cost_price приходит с фронта в рублях — переводим в копейки, тот
        // же приём, что и у items.*.cost_price в validateReceipt().
        $validated['cost_price'] = (int) round($validated['cost_price'] * 100);

        return $validated;
    }

    private function validateReceipt(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:clients,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id', $this->legalEntityBelongsToBranchRule($request)],
            'receipt_date' => ['required', 'date'],
            'supplier_document_number' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:255'],
        ]);

        $isSupplier = Client::where('id', $validated['supplier_id'])
            ->whereHas('roles', fn ($q) => $q->where('type', 'client_role')->where('value', self::SUPPLIER_ROLE))
            ->exists();

        if (! $isSupplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Поставщиком можно указать только клиента с ролью «'.self::SUPPLIER_ROLE_LABEL.'».',
            ]);
        }

        // cost_price приходит с фронта в рублях (как и раньше в
        // storeReceipt()) — переводим в копейки перед передачей в StockService.
        $validated['items'] = array_map(function ($item) {
            $item['cost_price'] = (int) round($item['cost_price'] * 100);

            return $item;
        }, $validated['items']);

        return $validated;
    }
}

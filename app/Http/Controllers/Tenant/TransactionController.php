<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ListView;
use App\Models\Lookup;
use App\Models\Position;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\WorkOrder;
use App\Services\FinanceService;
use App\Services\PeriodClosureService;
use App\Services\QueryFilterService;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with([
            'account',
            'branch' => fn ($q) => $q->withTrashed(),
            'category',
            'payable',
            'counterparty' => fn ($q) => $q->withTrashed(),
            'editor',
            'reconciler',
        ]);

        // Кастомный поиск по комментарию
        if ($request->filled('search')) {
            $searchTerm = '%'.$request->search.'%';
            $query->where('comment', 'LIKE', $searchTerm);
        }

        // Фильтры по контрагенту и основанию — составные условия (тип + id),
        // поэтому обрабатываются вручную и убираются из filters до QueryFilterService,
        // иначе тот сделал бы наивный WHERE по несуществующим колонкам.
        $filters = $request->input('filters', []);

        if (! empty($filters['counterparty'])) {
            [$counterpartyType, $counterpartyId] = explode(':', (string) $filters['counterparty']);
            $query->where('counterparty_type', $counterpartyType === 'employee' ? Employee::class : Client::class)
                ->where('counterparty_id', (int) $counterpartyId);
        }

        if (! empty($filters['work_order_id'])) {
            $query->where('payable_type', WorkOrder::class)->where('payable_id', (int) $filters['work_order_id']);
        }

        $params = $request->all();
        if (isset($params['filters'])) {
            unset($params['filters']['counterparty'], $params['filters']['work_order_id']);
        }

        $query = QueryFilterService::apply(
            $query,
            $params,
            [],
            allowedSorts: ['transaction_date', 'type', 'amount', 'comment', 'is_reconciled']
        );

        if (! $request->has('sort_by')) {
            $query->orderBy('transaction_date', 'desc')->orderBy('id', 'desc');
        }

        $transactions = $query->paginate(15)->withQueryString();

        // Человекочитаемые имена контрагентов — точечно после пагинации
        // (не через $appends, чтобы не тащить отношение в других местах)
        $transactions->getCollection()->each(function (Transaction $transaction) {
            $transaction->setAttribute('counterparty_label', $transaction->counterpartyLabel());
            $transaction->setAttribute('counterparty_kind', $transaction->counterparty_type === Employee::class ? 'employee' : 'client');
        });

        // Справочники для фильтров и создания
        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.balance']);
        $branches = auth()->user()->availableBranches()->forSelect()->get(['branches.id', 'branches.name']);
        $categories = TransactionCategory::where('is_active', true)->get(['id', 'name', 'type', 'value']);

        // Контрагенты для формы и фильтров — полные списки (конвенция проекта:
        // SearchableSelect фильтрует локально, HTTP-запросов за опциями не делает).
        // Роли клиентов нужны фронту, чтобы фильтровать список по статье операции
        // (поставщик / клиент / выплата зарплаты — только сотрудники).
        $clients = Client::with('roles:id,value')->orderBy('name')->get(['id', 'name', 'phone']);
        $employees = Employee::orderBy('last_name')->get(['id', 'first_name', 'last_name', 'middle_name']);

        // Роли клиентов как справочник для быстрого создания «поставщик»/«клиент» на лету
        // (роль при создании назначается исходя из выбранной статьи операции)
        $clientRoles = Lookup::where('type', 'client_role')->where('is_active', true)->get(['id', 'value', 'label']);

        // Заказ-наряды с невыплаченным остатком — для привязки ручной операции
        // к конкретному заказу («Основание»). Ограничены свежими, чтобы не
        // раздувать проп страницы — выбор и так ограничен суммой остатка долга.
        $baseOrders = WorkOrder::where('payment_status', '!=', 'paid')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'client_id'])
            ->load('client:id,name');

        // Для быстрого создания сотрудника-контрагента на лету (модалка «+»):
        // должность обязательна на бэкенде, отчество — только для RU/BY/KZ.
        $positions = Position::where('is_active', true)->get(['id', 'name']);

        $availableColumns = [
            ['key' => 'date', 'label' => 'Дата'],
            ['key' => 'type', 'label' => 'Тип'],
            ['key' => 'branch', 'label' => 'Локация'],
            ['key' => 'account', 'label' => 'Счет / Касса'],
            ['key' => 'category', 'label' => 'Статья'],
            ['key' => 'amount', 'label' => 'Сумма'],
            ['key' => 'counterparty', 'label' => 'Контрагент'],
            ['key' => 'base', 'label' => 'Основание'],
            ['key' => 'comment', 'label' => 'Комментарий'],
            ['key' => 'reconciled', 'label' => 'Сверено'],
        ];

        $listView = ListView::where('entity_type', 'transaction')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'branches' => $branches,
            'categories' => $categories,
            'clients' => $clients,
            'employees' => $employees,
            'clientRoles' => $clientRoles,
            'baseOrders' => $baseOrders,
            'positions' => $positions,
            'tenantCountry' => config('tenant.country_code', 'RU'),
            'filters' => $request->all(),
            'closedThroughDate' => PeriodClosureService::closedThroughDate(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:income,expense,transfer'],
            'account_id' => ['required_if:type,income,expense', 'nullable', 'exists:accounts,id'],
            'from_account_id' => ['required_if:type,transfer', 'nullable', 'exists:accounts,id'],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'exists:accounts,id', 'different:from_account_id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'transaction_category_id' => ['nullable', 'exists:transaction_categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:255'],
            'counterparty_type' => ['nullable', 'string', 'in:'.Client::class.','.Employee::class],
            'counterparty_id' => ['nullable', 'integer'],
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id'],
        ]);

        $amountCents = (int) round($validated['amount'] * 100);

        try {
            if ($validated['type'] === 'transfer') {
                // Переводы не имеют контрагента и основания — игнорируем их, даже если фронт прислал
                FinanceService::processTransfer([
                    'from_account_id' => $validated['from_account_id'],
                    'to_account_id' => $validated['to_account_id'],
                    'branch_id' => $validated['branch_id'],
                    'amount' => $amountCents,
                    'transaction_date' => $validated['transaction_date'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            } else {
                $data = [
                    'account_id' => $validated['account_id'],
                    'branch_id' => $validated['branch_id'],
                    'transaction_category_id' => $validated['transaction_category_id'] ?? null,
                    'type' => $validated['type'],
                    'amount' => $amountCents,
                    'transaction_date' => $validated['transaction_date'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                ];

                if (! empty($validated['work_order_id'])) {
                    // Оплата конкретного заказ-наряда: контрагент выводится из заказа
                    // и отдельно не принимается; сумма ограничена остатком долга
                    // серверно (та же проверка, что в WorkOrderController::processPayment).
                    $workOrder = WorkOrder::findOrFail($validated['work_order_id']);

                    if ($validated['type'] !== 'income') {
                        return redirect()->back()->withErrors(['error' => 'К заказ-наряду можно привязать только оплату (доход).']);
                    }

                    if ((int) $workOrder->branch_id !== (int) $validated['branch_id']) {
                        return redirect()->back()->withErrors(['error' => 'Заказ-наряд относится к другой локации — выберите локацию заказа или проведите операцию без основания.']);
                    }

                    $paidSoFar = $workOrder->transactions()->where('type', 'income')->sum('amount');
                    $remainingCents = max(0, $workOrder->final_amount - $paidSoFar);

                    if ($amountCents > $remainingCents) {
                        return redirect()->back()->withErrors(['error' => 'Сумма оплаты ('.number_format($amountCents / 100, 2, ',', ' ').' руб.) превышает остаток долга по заказ-наряду №'.$workOrder->id.' ('.number_format($remainingCents / 100, 2, ',', ' ').' руб.).']);
                    }

                    $data['payable_type'] = WorkOrder::class;
                    $data['payable_id'] = $workOrder->id;
                    $data['counterparty_type'] = Client::class;
                    $data['counterparty_id'] = $workOrder->client_id;
                    $data['transaction_category_id'] = $data['transaction_category_id'] ?? TransactionCategory::systemId('order_payment');

                    FinanceService::processTransaction($data, auth()->id());
                    $workOrder->syncPaymentStatus();
                } else {
                    // Доход/расход без основания — контрагент обязателен
                    if (empty($validated['counterparty_type']) || empty($validated['counterparty_id'])) {
                        return redirect()->back()->withErrors(['error' => 'Укажите контрагента операции.']);
                    }

                    $counterparty = $validated['counterparty_type'] === Employee::class
                        ? Employee::find($validated['counterparty_id'])
                        : Client::find($validated['counterparty_id']);

                    if (! $counterparty) {
                        return redirect()->back()->withErrors(['error' => 'Контрагент не найден или недоступен для выбранной локации.']);
                    }

                    if ((int) $counterparty->branch_id !== (int) $validated['branch_id']) {
                        return redirect()->back()->withErrors(['error' => 'Контрагент относится к другой локации — выберите локацию операции или другого контрагента.']);
                    }

                    $data['counterparty_type'] = $validated['counterparty_type'];
                    $data['counterparty_id'] = (int) $validated['counterparty_id'];

                    FinanceService::processTransaction($data, auth()->id());
                }
            }

            return redirect()->back()->with('success', 'Операция успешно проведена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при проведении операции: '.$e->getMessage()]);
        }
    }

    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'transaction_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:255'],
            'transaction_category_id' => ['nullable', 'exists:transaction_categories,id'],
            'counterparty_type' => ['nullable', 'string', 'in:'.Client::class.','.Employee::class],
            'counterparty_id' => ['nullable', 'integer'],
        ]);

        $data = [];
        if ($request->filled('amount')) {
            $newAmountCents = (int) round($validated['amount'] * 100);

            // Сумму перевода менять через инлайн-редактирование нельзя — у него две связанные ноги
            // на разных счетах, не отслеживаемые в паре. Отменить и провести заново — безопаснее.
            // Если сумма в форме совпадает с текущей (менялись другие поля), это не блокируется.
            if ($transaction->type === 'transfer' && $newAmountCents !== $transaction->amount) {
                return redirect()->back()->withErrors(['amount' => 'Сумму перевода нельзя изменить — отмените операцию и создайте новую.']);
            }

            $data['amount'] = $newAmountCents;
        }
        if ($request->has('transaction_date')) {
            $data['transaction_date'] = $validated['transaction_date'];
        }
        if ($request->has('comment')) {
            $data['comment'] = $validated['comment'];
        }
        if ($request->has('transaction_category_id')) {
            $data['transaction_category_id'] = $validated['transaction_category_id'];
        }

        // Контрагент редактируется только у операций без основания (переводы,
        // привязанные к заказу/накладной/начислению) — у тех он выводится из
        // самого документа и молча игнорируется, а не отклоняет весь запрос.
        if ($transaction->type !== 'transfer'
            && ! $transaction->payable_type
            && ($request->has('counterparty_type') || $request->has('counterparty_id'))
        ) {
            if (empty($validated['counterparty_type']) || empty($validated['counterparty_id'])) {
                return redirect()->back()->withErrors(['error' => 'Укажите контрагента операции.']);
            }

            $counterparty = $validated['counterparty_type'] === Employee::class
                ? Employee::find($validated['counterparty_id'])
                : Client::find($validated['counterparty_id']);

            if (! $counterparty) {
                return redirect()->back()->withErrors(['error' => 'Контрагент не найден или недоступен для локации операции.']);
            }

            if ((int) $counterparty->branch_id !== (int) $transaction->branch_id) {
                return redirect()->back()->withErrors(['error' => 'Контрагент относится к другой локации — выберите контрагента локации операции.']);
            }

            $data['counterparty_type'] = $validated['counterparty_type'];
            $data['counterparty_id'] = (int) $validated['counterparty_id'];
        }

        try {
            FinanceService::updateTransaction($transaction, $data, auth()->id());

            return redirect()->back()->with('success', 'Транзакция обновлена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при обновлении транзакции: '.$e->getMessage()]);
        }
    }

    public function destroy(Transaction $transaction)
    {
        try {
            FinanceService::revertTransaction($transaction);

            return redirect()->back()->with('success', 'Транзакция отменена, баланс восстановлен');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при отмене транзакции: '.$e->getMessage()]);
        }
    }

    /**
     * Отметка/снятие сверки с банковской выпиской. Не завязана на период/блокировки —
     * это подтверждение факта совпадения с выпиской, а не изменение суммы/даты операции.
     */
    public function toggleReconciled(Transaction $transaction)
    {
        if ($transaction->is_reconciled) {
            $transaction->update([
                'is_reconciled' => false,
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);

            return redirect()->back()->with('success', 'Отметка сверки снята');
        }

        $transaction->update([
            'is_reconciled' => true,
            'reconciled_at' => now(),
            'reconciled_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Транзакция отмечена как сверенная с банком');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:transactions,id'],
        ]);

        ExportEntitiesJob::dispatch('transactions', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}

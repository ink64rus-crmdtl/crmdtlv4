<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ListView;
use App\Services\FinanceService;
use App\Services\QueryFilterService;
use App\Services\PeriodClosureService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with(['account', 'branch', 'category', 'payable', 'editor', 'reconciler']);

        // Кастомный поиск по комментарию
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->search . '%';
            $query->where('comment', 'LIKE', $searchTerm);
        }

        $query = QueryFilterService::apply(
            $query,
            $request->all(),
            []
        );

        if (!$request->has('sort_by')) {
            $query->orderBy('transaction_date', 'desc')->orderBy('id', 'desc');
        }

        $transactions = $query->paginate(15)->withQueryString();
        
        // Справочники для фильтров и создания
        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type', 'accounts.balance']);
        $branches = auth()->user()->availableBranches()->forSelect()->get(['branches.id', 'branches.name']);
        $categories = TransactionCategory::where('is_active', true)->get(['id', 'name', 'type']);

        $availableColumns = [
            ['key' => 'date', 'label' => 'Дата'],
            ['key' => 'type', 'label' => 'Тип'],
            ['key' => 'account', 'label' => 'Счет / Касса'],
            ['key' => 'category', 'label' => 'Статья'],
            ['key' => 'amount', 'label' => 'Сумма'],
            ['key' => 'comment', 'label' => 'Основание / Комментарий'],
            ['key' => 'reconciled', 'label' => 'Сверено'],
        ];

        $listView = ListView::where('entity_type', 'transaction')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'branches' => $branches,
            'categories' => $categories,
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
        ]);

        $amountCents = (int) round($validated['amount'] * 100);

        try {
            if ($validated['type'] === 'transfer') {
                FinanceService::processTransfer([
                    'from_account_id' => $validated['from_account_id'],
                    'to_account_id' => $validated['to_account_id'],
                    'branch_id' => $validated['branch_id'],
                    'amount' => $amountCents,
                    'transaction_date' => $validated['transaction_date'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            } else {
                FinanceService::processTransaction([
                    'account_id' => $validated['account_id'],
                    'branch_id' => $validated['branch_id'],
                    'transaction_category_id' => $validated['transaction_category_id'] ?? null,
                    'type' => $validated['type'],
                    'amount' => $amountCents,
                    'transaction_date' => $validated['transaction_date'] ?? null,
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            }

            return redirect()->back()->with('success', 'Операция успешно проведена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при проведении операции: ' . $e->getMessage()]);
        }
    }

    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'transaction_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:255'],
            'transaction_category_id' => ['nullable', 'exists:transaction_categories,id'],
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

        try {
            FinanceService::updateTransaction($transaction, $data, auth()->id());
            return redirect()->back()->with('success', 'Транзакция обновлена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при обновлении транзакции: ' . $e->getMessage()]);
        }
    }

    public function destroy(Transaction $transaction)
    {
        try {
            FinanceService::revertTransaction($transaction);
            return redirect()->back()->with('success', 'Транзакция отменена, баланс восстановлен');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при отмене транзакции: ' . $e->getMessage()]);
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
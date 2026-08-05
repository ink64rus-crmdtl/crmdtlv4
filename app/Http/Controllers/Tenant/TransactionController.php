<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\Account;
use App\Models\Branch;
use App\Services\FinanceService;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with(['account', 'branch', 'category', 'payable']);

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
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->paginate(15)->withQueryString();
        
        // Справочники для фильтров и создания
        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.balance']);
        $branches = auth()->user()->availableBranches()->where('is_active', true)->get(['branches.id', 'branches.name']);
        $categories = TransactionCategory::where('is_active', true)->get(['id', 'name', 'type']);

        return Inertia::render('Finance/Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'branches' => $branches,
            'categories' => $categories,
            'filters' => $request->all(),
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
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            } else {
                FinanceService::processTransaction([
                    'account_id' => $validated['account_id'],
                    'branch_id' => $validated['branch_id'],
                    'transaction_category_id' => $validated['transaction_category_id'] ?? null,
                    'type' => $validated['type'],
                    'amount' => $amountCents,
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            }

            return redirect()->back()->with('success', 'Операция успешно проведена');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Ошибка при проведении операции: ' . $e->getMessage()]);
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
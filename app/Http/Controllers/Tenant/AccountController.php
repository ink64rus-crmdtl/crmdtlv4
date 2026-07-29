<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'legal_entity_id' => ['required', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,bank,acquiring'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bik' => ['nullable', 'string', 'max:50'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'corr_account' => ['nullable', 'string', 'max:100'],
            'is_default_for_invoicing' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        // Если счет становится дефолтным для счетов, снимаем флаг с остальных счетов этого юрлица
        if (!empty($validated['is_default_for_invoicing']) && $validated['is_default_for_invoicing']) {
            Account::where('legal_entity_id', $validated['legal_entity_id'])
                ->update(['is_default_for_invoicing' => false]);
        }

        Account::create([
            'legal_entity_id' => $validated['legal_entity_id'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'bank_name' => $validated['bank_name'] ?? null,
            'bik' => $validated['bik'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'corr_account' => $validated['corr_account'] ?? null,
            'is_default_for_invoicing' => $validated['is_default_for_invoicing'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Расчетный счет успешно создан');
    }

    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,bank,acquiring'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bik' => ['nullable', 'string', 'max:50'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'corr_account' => ['nullable', 'string', 'max:100'],
            'is_default_for_invoicing' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if (!empty($validated['is_default_for_invoicing']) && $validated['is_default_for_invoicing']) {
            Account::where('legal_entity_id', $account->legal_entity_id)
                ->where('id', '!=', $account->id)
                ->update(['is_default_for_invoicing' => false]);
        }

        $account->update($validated);

        return redirect()->back()->with('success', 'Счет обновлен');
    }

    public function destroy(Account $account)
    {
        $account->delete();

        return redirect()->back()->with('success', 'Счет удален');
    }
}
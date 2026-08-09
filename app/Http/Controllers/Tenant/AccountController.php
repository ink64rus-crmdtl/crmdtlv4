<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\DadataService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Автоподстановка названия банка и корсчёта по БИК (DaData) — фронт
     * (Settings/LegalEntities/Index.vue) держит эти поля нередактируемыми
     * именно потому, что заполняются они только отсюда, без ручного ввода
     * (защита от опечаток/несовпадения банка и БИК в документах).
     */
    public function lookupBik(Request $request)
    {
        $validated = $request->validate([
            'bik' => ['required', 'string', 'min:5', 'max:20'],
        ]);

        if (!DadataService::isConfigured()) {
            return response()->json(['found' => false, 'configured' => false]);
        }

        $result = DadataService::lookupBank($validated['bik']);

        if (!$result) {
            return response()->json(['found' => false, 'configured' => true]);
        }

        return response()->json(['found' => true, 'configured' => true, ...$result]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'legal_entity_id' => ['required', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,bank,acquiring'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            'commission_percent' => $validated['type'] === 'acquiring' ? ($validated['commission_percent'] ?? 0) : null,
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
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
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

        $validated['commission_percent'] = $validated['type'] === 'acquiring' ? ($validated['commission_percent'] ?? 0) : null;

        $account->update($validated);

        return redirect()->back()->with('success', 'Счет обновлен');
    }

    public function destroy(Account $account)
    {
        $account->delete();

        return redirect()->back()->with('success', 'Счет удален');
    }
}
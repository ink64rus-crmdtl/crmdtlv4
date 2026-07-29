<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Services\CountryConfigService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalEntityController extends Controller
{
    public function index(): Response
    {
        $legalEntities = LegalEntity::with('accounts')->orderBy('id', 'desc')->get();
        $tenantCountry = config('tenant.country_code', 'RU');
        $countryConfig = CountryConfigService::getForCountry($tenantCountry);

        return Inertia::render('Settings/LegalEntities/Index', [
            'legalEntities' => $legalEntities,
            'tenantCountry' => $tenantCountry,
            'countryConfig' => $countryConfig,
        ]);
    }

    public function store(Request $request)
    {
        $tenantCountry = config('tenant.country_code', 'RU');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'requisites' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);

        $requisites = $validated['requisites'] ?? [];
        $requisites['country_code'] = $tenantCountry;

        LegalEntity::create([
            'name' => $validated['name'],
            'tax_id' => $validated['tax_id'] ?? ($requisites['inn'] ?? $requisites['unp'] ?? $requisites['bin_iin'] ?? $requisites['tax_id'] ?? null),
            'requisites' => $requisites,
            'is_active' => $validated['is_active'],
        ]);

        return redirect()->back()->with('success', 'Юридическое лицо успешно создано');
    }

    public function update(Request $request, LegalEntity $legalEntity)
    {
        $tenantCountry = config('tenant.country_code', 'RU');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'requisites' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);

        $requisites = $validated['requisites'] ?? [];
        $requisites['country_code'] = $tenantCountry;

        $legalEntity->update([
            'name' => $validated['name'],
            'tax_id' => $validated['tax_id'] ?? ($requisites['inn'] ?? $requisites['unp'] ?? $requisites['bin_iin'] ?? $requisites['tax_id'] ?? null),
            'requisites' => $requisites,
            'is_active' => $validated['is_active'],
        ]);

        return redirect()->back()->with('success', 'Данные юрлица обновлены');
    }

    public function destroy(LegalEntity $legalEntity)
    {
        $legalEntity->delete();

        return redirect()->back()->with('success', 'Юридическое лицо удалено');
    }
}
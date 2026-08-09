<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Services\CountryConfigService;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalEntityController extends Controller
{
    public function index(): Response
    {
        $query = LegalEntity::with(['accounts', 'branches:branches.id,branches.name']);

        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name', 'tax_id']
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $legalEntities = $query->paginate(15)->withQueryString();

        $tenantCountry = config('tenant.country_code', 'RU');
        $countryConfig = CountryConfigService::getForCountry($tenantCountry);

        return Inertia::render('Settings/LegalEntities/Index', [
            'legalEntities' => $legalEntities,
            'filters' => request()->all(),
            'tenantCountry' => $tenantCountry,
            'countryConfig' => $countryConfig,
            // Для мультивыбора точек в форме юрлица — привязка настраивается
            // отсюда (многие-ко-многим, branch_legal_entity), не со стороны точки.
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $tenantCountry = config('tenant.country_code', 'RU');
        $bootstrapping = Branch::count() === 0;

        // Юрлицо без привязки хотя бы к одной точке заводить нельзя (иначе
        // "висит в воздухе" непонятно от чьего имени работает) — ЕДИНСТВЕННОЕ
        // исключение: у тенанта ещё вообще нет точек (самый первый заход в
        // систему) — тогда branch_ids не спрашиваем, точка "Основная"
        // создастся и привяжется автоматически ниже.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'requisites' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
            'branch_ids' => [$bootstrapping ? 'nullable' : 'required', 'array', $bootstrapping ? 'sometimes' : 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $requisites = $validated['requisites'] ?? [];
        $requisites['country_code'] = $tenantCountry;

        // requisites — источник актуального значения: форма (Settings/LegalEntities/
        // Index.vue) не даёт править top-level tax_id напрямую, только поле "ИНН"/"УНП"/
        // и т.п. внутри динамической схемы (form.requisites[field.key]) — top-level
        // form.tax_id заполняется ОДИН РАЗ при открытии модалки и после этого не
        // меняется. Раньше $validated['tax_id'] (эта самая устаревшая копия) стоял
        // первым в ??-цепочке и перебивал свежий requisites.inn — из-за этого
        // проверка уникальности сверялась со СТАРЫМ ИНН, а не с тем, что реально
        // ввёл пользователь (баг: смена ИНН на новый ложно отклонялась как "уже
        // существует", если старый ИНН совпадал с чужим).
        $taxId = $requisites['inn'] ?? $requisites['unp'] ?? $requisites['bin_iin'] ?? $requisites['tax_id'] ?? $validated['tax_id'] ?? null;

        if ($taxId && LegalEntity::where('tax_id', $taxId)->exists()) {
            return back()->withErrors(['tax_id' => 'Юрлицо с таким налоговым номером (ИНН) уже существует.']);
        }

        $legalEntity = LegalEntity::create([
            'name' => $validated['name'],
            'tax_id' => $taxId,
            'requisites' => $requisites,
            'is_active' => $validated['is_active'],
        ]);

        // Точка — обязательная операционная единица (branch_id есть у заказов,
        // клиентов, транзакций и т.п.), но количественно с юрлицами никак не
        // связана: даже одно юрлицо и одна физическая точка требуют ровно
        // одной точки. У свежего тенанта её никто не создаёт автоматически
        // (RegisterTenantController заводит только тенанта/домен/роли/админа) —
        // без этой подсказки первое же создание заказа упёрлось бы в пустой
        // список точек. Условие "Branch::count() === 0" (а не "это первое
        // юрлицо") — чтобы сработало и для тенанта, который все точки удалил,
        // и не сработало повторно при создании ВТОРОГО юрлица, когда точка уже есть.
        if ($bootstrapping) {
            $branch = Branch::create([
                'name' => 'Основная',
                'is_active' => true,
            ]);
            $legalEntity->branches()->attach($branch->id);

            return redirect()->back()->with('success', 'Юридическое лицо создано. Также автоматически создана точка «Основная» — это первая точка в системе, при необходимости переименуйте её в Настройках → Точки.');
        }

        $legalEntity->branches()->sync($validated['branch_ids']);

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
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $requisites = $validated['requisites'] ?? [];
        $requisites['country_code'] = $tenantCountry;

        // requisites — источник актуального значения: форма (Settings/LegalEntities/
        // Index.vue) не даёт править top-level tax_id напрямую, только поле "ИНН"/"УНП"/
        // и т.п. внутри динамической схемы (form.requisites[field.key]) — top-level
        // form.tax_id заполняется ОДИН РАЗ при открытии модалки и после этого не
        // меняется. Раньше $validated['tax_id'] (эта самая устаревшая копия) стоял
        // первым в ??-цепочке и перебивал свежий requisites.inn — из-за этого
        // проверка уникальности сверялась со СТАРЫМ ИНН, а не с тем, что реально
        // ввёл пользователь (баг: смена ИНН на новый ложно отклонялась как "уже
        // существует", если старый ИНН совпадал с чужим).
        $taxId = $requisites['inn'] ?? $requisites['unp'] ?? $requisites['bin_iin'] ?? $requisites['tax_id'] ?? $validated['tax_id'] ?? null;

        if ($taxId && LegalEntity::where('tax_id', $taxId)->where('id', '!=', $legalEntity->id)->exists()) {
            return back()->withErrors(['tax_id' => 'Юрлицо с таким налоговым номером (ИНН) уже существует.']);
        }

        $legalEntity->update([
            'name' => $validated['name'],
            'tax_id' => $taxId,
            'requisites' => $requisites,
            'is_active' => $validated['is_active'],
        ]);

        $legalEntity->branches()->sync($validated['branch_ids']);

        return redirect()->back()->with('success', 'Данные юрлица обновлены');
    }

    public function destroy(LegalEntity $legalEntity)
    {
        $legalEntity->delete();

        if (session('current_legal_entity_id') == $legalEntity->id) {
            session(['current_legal_entity_id' => 'all']);
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Юридическое лицо удалено');
    }

    public function switch(Request $request, ?LegalEntity $legalEntity = null)
    {
        if ($legalEntity && $legalEntity->exists) {
            session(['current_legal_entity_id' => $legalEntity->id]);

            // Точка теперь может относиться сразу к нескольким юрлицам —
            // если текущая выбранная точка входит и в новое юрлицо тоже, не
            // заставляем пользователя перевыбирать её просто из-за смены
            // юрлица в сайдбаре. Сбрасываем на "Все" только если точка
            // реально не относится к новому юрлицу (SetBranchContext и так
            // поймал бы это на следующем запросе, но лучше сразу).
            $currentBranchId = session('current_branch_id');
            if ($currentBranchId && $currentBranchId !== 'all') {
                $stillValid = $legalEntity->branches()->where('branches.id', (int) $currentBranchId)->exists();
                if (!$stillValid) {
                    session(['current_branch_id' => 'all']);
                }
            }
        } else {
            session(['current_legal_entity_id' => 'all']);
            session(['current_branch_id' => 'all']);
        }

        session()->save();
        return redirect()->back();
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:legal_entities,id'],
        ]);

        LegalEntity::whereIn('id', $validated['ids'])->delete();

        if (in_array(session('current_legal_entity_id'), $validated['ids'])) {
            session(['current_legal_entity_id' => 'all']);
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Выбранные юридические лица удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:legal_entities,id'],
        ]);

        ExportEntitiesJob::dispatch('legal_entities', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $query = Branch::with('legalEntity');
        
        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name', 'city', 'address', 'phone']
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $branchesList = $query->paginate(15)->withQueryString();
        
        $legalEntities = LegalEntity::where('is_active', true)->get(['id', 'name']);

        return Inertia::render('Settings/Branches/Index', [
            'branchesList' => $branchesList,
            'filters' => request()->all(),
            'legalEntities' => $legalEntities,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'working_hours' => ['nullable', 'array', 'size:7'],
            'working_hours.*.day' => ['required_with:working_hours', 'string'],
            'working_hours.*.is_open' => ['required_with:working_hours', 'boolean'],
            'working_hours.*.open' => ['nullable', 'string'],
            'working_hours.*.close' => ['nullable', 'string'],
        ]);

        Branch::create($validated);

        return redirect()->back()->with('success', 'Филиал успешно создан');
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'legal_entity_id' => ['nullable', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'working_hours' => ['nullable', 'array', 'size:7'],
            'working_hours.*.day' => ['required_with:working_hours', 'string'],
            'working_hours.*.is_open' => ['required_with:working_hours', 'boolean'],
            'working_hours.*.open' => ['nullable', 'string'],
            'working_hours.*.close' => ['nullable', 'string'],
        ]);

        $branch->update($validated);

        return redirect()->back()->with('success', 'Филиал обновлен');
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();

        // Если удалили текущий филиал, сбрасываем сессию на All
        if (session('current_branch_id') == $branch->id) {
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Филиал удален');
    }

    public function switch(Request $request, ?Branch $branch = null)
    {
        if ($branch && $branch->exists) {
            session(['current_branch_id' => $branch->id]);
            // Автоматически переключаем юрлицо на то, к которому принадлежит филиал
            if ($branch->legal_entity_id) {
                session(['current_legal_entity_id' => $branch->legal_entity_id]);
            }
        } else {
            session(['current_branch_id' => 'all']);
        }
        
        session()->save();
        return redirect()->back();
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:branches,id'],
        ]);

        Branch::whereIn('id', $validated['ids'])->delete();

        if (in_array(session('current_branch_id'), $validated['ids'])) {
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Выбранные филиалы удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:branches,id'],
        ]);

        ExportEntitiesJob::dispatch('branches', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}
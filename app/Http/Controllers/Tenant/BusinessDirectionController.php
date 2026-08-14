<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Branch;
use App\Models\BusinessDirection;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BusinessDirectionController extends Controller
{
    public function index(): Response
    {
        $query = BusinessDirection::with('branches');

        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name'],
            allowedSorts: ['name', 'is_active']
        );

        if (! request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $businessDirections = $query->paginate(15)->withQueryString();

        $branches = Branch::forSelect()->get(['id', 'name']);

        return Inertia::render('Settings/BusinessDirections/Index', [
            'businessDirections' => $businessDirections,
            'filters' => request()->all(),
            'branches' => $branches,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['exists:branches,id'],
        ]);

        DB::transaction(function () use ($validated) {
            $businessDirection = BusinessDirection::create([
                'name' => $validated['name'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if (! empty($validated['branch_ids'])) {
                $businessDirection->branches()->sync($validated['branch_ids']);
            }
        });

        return redirect()->back()->with('success', 'Направление успешно создано');
    }

    public function update(Request $request, BusinessDirection $businessDirection)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['exists:branches,id'],
        ]);

        DB::transaction(function () use ($validated, $businessDirection) {
            $businessDirection->update([
                'name' => $validated['name'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if (isset($validated['branch_ids'])) {
                $businessDirection->branches()->sync($validated['branch_ids']);
            } else {
                $businessDirection->branches()->sync([]);
            }
        });

        return redirect()->back()->with('success', 'Направление обновлено');
    }

    public function destroy(BusinessDirection $businessDirection)
    {
        $businessDirection->delete();

        return redirect()->back()->with('success', 'Направление удалено');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:business_directions,id'],
        ]);

        BusinessDirection::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные направления удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:business_directions,id'],
        ]);

        ExportEntitiesJob::dispatch('business_directions', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}

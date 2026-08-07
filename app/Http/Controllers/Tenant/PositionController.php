<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(): Response
    {
        $query = Position::query();
        
        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name']
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $positions = $query->paginate(15)->withQueryString();

        return Inertia::render('HR/Positions/Index', [
            'positions' => $positions,
            'filters' => request()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'payroll_role' => ['required', 'string', 'in:admin,worker'],
        ]);

        Position::create([
            'name' => [app()->getLocale() => $validated['name']],
            'is_active' => $validated['is_active'] ?? true,
            'payroll_role' => $validated['payroll_role'],
        ]);

        return redirect()->back()->with('success', 'Должность успешно создана');
    }

    public function update(Request $request, Position $position)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'payroll_role' => ['required', 'string', 'in:admin,worker'],
        ]);

        $name = $position->getTranslations('name');
        $name[app()->getLocale()] = $validated['name'];

        $position->update([
            'name' => $name,
            'is_active' => $validated['is_active'] ?? true,
            'payroll_role' => $validated['payroll_role'],
        ]);

        return redirect()->back()->with('success', 'Должность обновлена');
    }

    public function destroy(Position $position)
    {
        $position->delete();

        return redirect()->back()->with('success', 'Должность удалена');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:positions,id'],
        ]);

        Position::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные должности удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:positions,id'],
        ]);

        ExportEntitiesJob::dispatch('positions', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}
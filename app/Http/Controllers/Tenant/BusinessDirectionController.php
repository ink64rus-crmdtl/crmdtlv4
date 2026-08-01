<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BusinessDirection;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BusinessDirectionController extends Controller
{
    public function index(): Response
    {
        $businessDirections = BusinessDirection::with('branches')->orderBy('id', 'desc')->get();
        $branches = Branch::where('is_active', true)->get(['id', 'name']);

        return Inertia::render('Settings/BusinessDirections/Index', [
            'businessDirections' => $businessDirections,
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

            if (!empty($validated['branch_ids'])) {
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

        $items = BusinessDirection::with('branches')->whereIn('id', $validated['ids'])->get();
        
        $filename = 'business_directions_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];
        
        $callback = function() use($items) {
            $file = fopen('php://output', 'w');
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            fputcsv($file, ['ID', 'Название', 'Филиалы', 'Статус'], ';');
            
            foreach ($items as $item) {
                $branches = $item->branches->pluck('name')->join(', ');
                fputcsv($file, [
                    $item->id,
                    $item->name,
                    $branches ?: 'Во всех филиалах',
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
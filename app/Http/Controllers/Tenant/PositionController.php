<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(): Response
    {
        $positions = Position::orderBy('id', 'desc')->get();

        return Inertia::render('HR/Positions/Index', [
            'positions' => $positions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        Position::create([
            'name' => [app()->getLocale() => $validated['name']],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Должность успешно создана');
    }

    public function update(Request $request, Position $position)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $name = $position->name;
        $name[app()->getLocale()] = $validated['name'];

        $position->update([
            'name' => $name,
            'is_active' => $validated['is_active'] ?? true,
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

        $items = Position::whereIn('id', $validated['ids'])->get();
        
        $filename = 'positions_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];
        
        $callback = function() use($items) {
            $file = fopen('php://output', 'w');
            // Добавляем BOM для корректного отображения UTF-8 в Excel
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            fputcsv($file, ['ID', 'Название', 'Статус'], ';');
            
            foreach ($items as $item) {
                $name = is_array($item->name) ? ($item->name['ru'] ?? current($item->name)) : $item->name;
                fputcsv($file, [
                    $item->id,
                    $name,
                    $item->is_active ? 'Активно' : 'Неактивно'
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
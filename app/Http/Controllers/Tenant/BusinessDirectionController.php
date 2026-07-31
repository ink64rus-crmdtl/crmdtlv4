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
}
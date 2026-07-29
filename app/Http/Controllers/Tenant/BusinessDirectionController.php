<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BusinessDirection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessDirectionController extends Controller
{
    public function index(): Response
    {
        $businessDirections = BusinessDirection::orderBy('id', 'desc')->get();

        return Inertia::render('Settings/BusinessDirections/Index', [
            'businessDirections' => $businessDirections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        BusinessDirection::create($validated);

        return redirect()->back()->with('success', 'Направление успешно создано');
    }

    public function update(Request $request, BusinessDirection $businessDirection)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $businessDirection->update($validated);

        return redirect()->back()->with('success', 'Направление обновлено');
    }

    public function destroy(BusinessDirection $businessDirection)
    {
        $businessDirection->delete();

        return redirect()->back()->with('success', 'Направление удалено');
    }
}
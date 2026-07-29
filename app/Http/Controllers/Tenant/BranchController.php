<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $branchesList = Branch::orderBy('id', 'desc')->get();

        return Inertia::render('Settings/Branches/Index', [
            'branchesList' => $branchesList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $branch = Branch::create($validated);

        // Если это первый созданный филиал, сразу делаем его активным в сессии
        if (!session('current_branch_id')) {
            session(['current_branch_id' => $branch->id]);
        }

        return redirect()->back()->with('success', 'Филиал успешно создан');
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $branch->update($validated);

        return redirect()->back()->with('success', 'Филиал обновлен');
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();

        // Если удалили текущий филиал, сбрасываем сессию
        if (session('current_branch_id') == $branch->id) {
            session()->forget('current_branch_id');
        }

        return redirect()->back()->with('success', 'Филиал удален');
    }

    public function switch(Request $request, Branch $branch)
    {
        session(['current_branch_id' => $branch->id]);
        session()->save(); // Принудительно сохраняем сессию до редиректа
        
        return redirect()->back();
    }
}
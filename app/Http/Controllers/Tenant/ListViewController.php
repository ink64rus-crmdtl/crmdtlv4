<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ListView;
use Illuminate\Http\Request;

class ListViewController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:255'],
            'visible_columns' => ['required', 'array'],
        ]);

        ListView::updateOrCreate(
            [
                'entity_type' => $validated['entity_type'],
                'user_id' => auth()->id(),
            ],
            [
                'name' => 'Default',
                'visible_columns' => $validated['visible_columns'],
            ]
        );

        return redirect()->back()->with('success', 'Настройки таблицы сохранены');
    }
}
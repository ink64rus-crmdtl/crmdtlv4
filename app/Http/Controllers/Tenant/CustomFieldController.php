<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(): Response
    {
        $customFields = CustomFieldDefinition::orderBy('entity_type')->orderBy('sort_order')->get();

        return Inertia::render('Settings/CustomFields/Index', [
            'customFields' => $customFields,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'in:client,vehicle,work_order'],
            'label' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:text,number,date,select,checkbox'],
            'options' => ['nullable', 'string'],
            'is_required' => ['boolean'],
            'is_filterable' => ['boolean'],
            'is_visible_in_list' => ['boolean'],
        ]);

        $key = $validated['key'] ?: Str::slug($validated['label'], '_');

        $options = null;
        if ($validated['type'] === 'select' && !empty($validated['options'])) {
            $options = array_map('trim', explode(',', $validated['options']));
        }

        CustomFieldDefinition::create([
            'entity_type' => $validated['entity_type'],
            'key' => $key,
            'label' => [app()->getLocale() => $validated['label']],
            'type' => $validated['type'],
            'options' => $options,
            'is_required' => $validated['is_required'] ?? false,
            'is_filterable' => $validated['is_filterable'] ?? false,
            'is_visible_in_list' => $validated['is_visible_in_list'] ?? true,
            'created_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Поле успешно создано');
    }

    public function update(Request $request, CustomFieldDefinition $customField)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:text,number,date,select,checkbox'],
            'options' => ['nullable', 'string'],
            'is_required' => ['boolean'],
            'is_filterable' => ['boolean'],
            'is_visible_in_list' => ['boolean'],
        ]);

        $options = null;
        if ($validated['type'] === 'select' && !empty($validated['options'])) {
            $options = array_map('trim', explode(',', $validated['options']));
        }

        $label = $customField->label;
        $label[app()->getLocale()] = $validated['label'];

        $customField->update([
            'label' => $label,
            'type' => $validated['type'],
            'options' => $options,
            'is_required' => $validated['is_required'] ?? false,
            'is_filterable' => $validated['is_filterable'] ?? false,
            'is_visible_in_list' => $validated['is_visible_in_list'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Поле обновлено');
    }

    public function destroy(CustomFieldDefinition $customField)
    {
        $customField->delete();

        return redirect()->back()->with('success', 'Поле удалено');
    }
}
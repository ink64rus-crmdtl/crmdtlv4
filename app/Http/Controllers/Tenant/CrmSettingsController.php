<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmSettingsController extends Controller
{
    public function index(): Response
    {
        $strictPlateValidation = Setting::where('key', 'strict_plate_validation')->value('value') === '1';
        $pricingBasis = Setting::where('key', 'pricing_basis')->value('value') ?? 'none';
        $bonusRubPerPoint = Setting::where('key', 'bonus_rub_per_point')->value('value') ?? '1';
        $defaultWorkingHoursRaw = Setting::where('key', 'default_working_hours')->value('value');
        $calendarColorSource = Setting::where('key', 'calendar_color_source')->value('value') ?? 'status';

        return Inertia::render('Settings/CRM/Index', [
            'strictPlateValidation' => $strictPlateValidation,
            'pricingBasis' => $pricingBasis,
            'bonusRubPerPoint' => (float) $bonusRubPerPoint,
            'defaultWorkingHours' => $defaultWorkingHoursRaw ? json_decode($defaultWorkingHoursRaw, true) : null,
            'calendarColorSource' => $calendarColorSource,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strict_plate_validation' => ['required', 'boolean'],
            'pricing_basis' => ['required', 'string', 'in:none,vehicle_body,vehicle_class'],
            'bonus_rub_per_point' => ['required', 'numeric', 'min:0'],
            'default_working_hours' => ['nullable', 'array', 'size:7'],
            'default_working_hours.*.day' => ['required_with:default_working_hours', 'string'],
            'default_working_hours.*.is_open' => ['required_with:default_working_hours', 'boolean'],
            'default_working_hours.*.open' => ['nullable', 'string'],
            'default_working_hours.*.close' => ['nullable', 'string'],
            'calendar_color_source' => ['required', 'string', 'in:status,employee,post'],
        ]);

        Setting::updateOrCreate(
            ['key' => 'strict_plate_validation'],
            ['value' => $validated['strict_plate_validation'] ? '1' : '0']
        );

        Setting::updateOrCreate(
            ['key' => 'pricing_basis'],
            ['value' => $validated['pricing_basis']]
        );

        Setting::updateOrCreate(
            ['key' => 'bonus_rub_per_point'],
            ['value' => (string) $validated['bonus_rub_per_point']]
        );

        Setting::updateOrCreate(
            ['key' => 'default_working_hours'],
            ['value' => isset($validated['default_working_hours']) ? json_encode($validated['default_working_hours']) : null]
        );

        Setting::updateOrCreate(
            ['key' => 'calendar_color_source'],
            ['value' => $validated['calendar_color_source']]
        );

        return redirect()->back()->with('success', 'Настройки CRM успешно сохранены');
    }
}
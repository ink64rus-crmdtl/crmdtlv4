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
        $calendarColorSource = Setting::where('key', 'calendar_color_source')->value('value') ?? 'status';

        return Inertia::render('Settings/CRM/Index', [
            'strictPlateValidation' => $strictPlateValidation,
            'pricingBasis' => $pricingBasis,
            'calendarColorSource' => $calendarColorSource,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strict_plate_validation' => ['required', 'boolean'],
            'pricing_basis' => ['required', 'string', 'in:none,vehicle_body,vehicle_class'],
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
            ['key' => 'calendar_color_source'],
            ['value' => $validated['calendar_color_source']]
        );

        return redirect()->back()->with('success', 'Настройки CRM успешно сохранены');
    }
}
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

        return Inertia::render('Settings/CRM/Index', [
            'strictPlateValidation' => $strictPlateValidation,
            'pricingBasis' => $pricingBasis,
            'bonusRubPerPoint' => (float) $bonusRubPerPoint,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strict_plate_validation' => ['required', 'boolean'],
            'pricing_basis' => ['required', 'string', 'in:none,vehicle_body,vehicle_class'],
            'bonus_rub_per_point' => ['required', 'numeric', 'min:0'],
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

        return redirect()->back()->with('success', 'Настройки CRM успешно сохранены');
    }
}
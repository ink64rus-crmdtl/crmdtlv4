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

        return Inertia::render('Settings/CRM/Index', [
            'strictPlateValidation' => $strictPlateValidation,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strict_plate_validation' => ['required', 'boolean'],
        ]);

        Setting::updateOrCreate(
            ['key' => 'strict_plate_validation'],
            ['value' => $validated['strict_plate_validation'] ? '1' : '0']
        );

        return redirect()->back()->with('success', 'Настройки CRM успешно сохранены');
    }
}
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ClientGroup;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Все настройки программы лояльности (Фаза 14) в одном месте — курс
 * конвертации баллов в рубли (раньше жил внутри общих "Настроек CRM",
 * см. CrmSettingsController — вынесен сюда для декластеризации) и
 * управление грейдами клиентов (ClientGroup.cashback_percent). CRUD групп —
 * те же маршруты crm.client-groups.*, что использует и модалка "Группы
 * клиентов" в CRM/Clients/Index.vue (ClientController::storeGroup/
 * updateGroup/destroyGroup) — сознательно НЕ дублируется здесь: одна точка
 * входа с фронта в бэкенд, эта страница просто ещё один UI поверх неё.
 */
class LoyaltySettingsController extends Controller
{
    public function index(): Response
    {
        $bonusRubPerPoint = Setting::where('key', 'bonus_rub_per_point')->value('value') ?? '1';

        return Inertia::render('Settings/Loyalty/Index', [
            'bonusRubPerPoint' => (float) $bonusRubPerPoint,
            'clientGroups' => ClientGroup::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bonus_rub_per_point' => ['required', 'numeric', 'min:0'],
        ]);

        Setting::updateOrCreate(
            ['key' => 'bonus_rub_per_point'],
            ['value' => (string) $validated['bonus_rub_per_point']]
        );

        return redirect()->back()->with('success', 'Настройки программы лояльности сохранены');
    }
}

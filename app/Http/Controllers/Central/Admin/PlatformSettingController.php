<?php

namespace App\Http\Controllers\Central\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Общеплатформенные настройки (central БД) — общие на всю платформу токены
 * сторонних сервисов, читаются тенантским кодом ТОЛЬКО через
 * tenancy()->central(...) (wappi_master_token — см. WappiProProvider;
 * dadata_api_key — см. App\Services\DadataService). Список ключей зашит
 * явно (а не произвольная форма) — чтобы не завести в этой таблице что
 * попало без единого места, которое реально это значение читает.
 */
class PlatformSettingController extends Controller
{
    public const KEYS = [
        'wappi_master_token' => 'Токен Wappi.Pro (общий на аккаунт)',
        'dadata_api_key' => 'Токен DaData (автоподстановка банка по БИК)',
    ];

    public function index(): Response
    {
        $values = collect(self::KEYS)->keys()->mapWithKeys(fn ($key) => [
            $key => PlatformSetting::get($key),
        ]);

        return Inertia::render('Central/Admin/Settings/Index', [
            'keys' => self::KEYS,
            'values' => $values,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($validated['values'] as $key => $value) {
            if (!array_key_exists($key, self::KEYS)) {
                continue;
            }

            // Пустое поле — не значит "стереть": пароль/токен не отображается
            // обратно в форме из соображений безопасности, поэтому пустое
            // значит "не менять", как и в Settings/Channels на тенантской стороне.
            if ($value === null || trim($value) === '') {
                continue;
            }

            PlatformSetting::put($key, $value);
        }

        return redirect()->back()->with('success', 'Настройки сохранены');
    }
}

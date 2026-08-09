<?php

namespace App\Services;

use App\Models\Central\PlatformSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Автоподстановка банка по БИК (DaData Suggestions API) — токен общий на
 * платформу, вносится администратором платформы в /admin/settings
 * (App\Http\Controllers\Central\Admin\PlatformSettingController::KEYS,
 * ключ dadata_api_key), читается ТОЛЬКО через tenancy()->central(...) — тот
 * же принцип, что у wappi_master_token (см. WappiProProvider). Тенант сам
 * ключ не вводит и не видит.
 */
class DadataService
{
    private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/bank';

    public static function isConfigured(): bool
    {
        return (bool) tenancy()->central(fn () => PlatformSetting::get('dadata_api_key'));
    }

    /**
     * @return array{bank_name: string, corr_account: string, bik: string}|null null — БИК не найден, ключ не настроен или ошибка запроса
     */
    public static function lookupBank(string $bik): ?array
    {
        $token = tenancy()->central(fn () => PlatformSetting::get('dadata_api_key'));

        if (!$token) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Token ' . $token,
            ])->timeout(5)->post(self::ENDPOINT, ['query' => $bik]);
        } catch (\Throwable $e) {
            Log::warning('DaData: ошибка запроса поиска банка по БИК', ['bik' => $bik, 'message' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $suggestion = $response->json('suggestions.0.data');

        // Банк с отозванной лицензией DaData всё равно возвращает в suggestions —
        // реквизиты такого банка использовать в новом документе не нужно.
        if (!$suggestion || ($suggestion['state']['status'] ?? null) !== 'ACTIVE') {
            return null;
        }

        return [
            'bank_name' => $suggestion['name']['payment'] ?? $suggestion['name']['short'] ?? '',
            'corr_account' => $suggestion['correspondent_account'] ?? '',
            'bik' => $suggestion['bic'] ?? $bik,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Central\PlatformSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DaData Suggestions API — токен общий на платформу, вносится администратором
 * платформы в /admin/settings (App\Http\Controllers\Central\Admin\
 * PlatformSettingController::KEYS, ключ dadata_api_key), читается ТОЛЬКО
 * через tenancy()->central(...) — тот же принцип, что у wappi_master_token
 * (см. WappiProProvider). Тенант сам ключ не вводит и не видит.
 *
 * Оба метода (lookupBank/suggestParty) — один и тот же продукт DaData
 * (Suggestions API), авторизация одним и тем же токеном (заголовок
 * Authorization: Token). Секретный ключ DaData (второй, который выдаётся
 * в личном кабинете) относится к отдельному продукту — API «Стандартизация»
 * (Clean API) — и здесь не используется вообще, поэтому в настройках
 * платформы для него нет отдельного поля: это не забытое поле, а осознанно
 * ненужное для функциональности, которая реализована.
 */
class DadataService
{
    private const BANK_ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/bank';

    private const PARTY_ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party';

    public static function isConfigured(): bool
    {
        return (bool) tenancy()->central(fn () => PlatformSetting::get('dadata_api_key'));
    }

    /**
     * @return array{bank_name: string, corr_account: string, bik: string}|null null — БИК не найден, ключ не настроен или ошибка запроса
     */
    public static function lookupBank(string $bik): ?array
    {
        $response = self::request(self::BANK_ENDPOINT, ['query' => $bik]);

        if (! $response) {
            return null;
        }

        $suggestion = $response->json('suggestions.0.data');

        // Банк с отозванной лицензией DaData всё равно возвращает в suggestions —
        // реквизиты такого банка использовать в новом документе не нужно.
        if (! $suggestion || ($suggestion['state']['status'] ?? null) !== 'ACTIVE') {
            return null;
        }

        return [
            'bank_name' => $suggestion['name']['payment'] ?? $suggestion['name']['short'] ?? '',
            'corr_account' => $suggestion['correspondent_account'] ?? '',
            'bik' => $suggestion['bic'] ?? $bik,
        ];
    }

    /**
     * Поиск организации/ИП — ОДИН и тот же DaData-эндпоинт по названию И по
     * ИНН одновременно (DaData сам распознаёт цифровой запрос как ИНН) —
     * поэтому один метод обслуживает оба поля формы (см. CompanySuggestInput.vue).
     *
     * director_name/director_position заполняются ТОЛЬКО для юрлиц (type=LEGAL,
     * данные из management.*) — у ИП нет отдельного "руководителя", это то же
     * лицо, что и сам предприниматель, поэтому подставлять туда что-то было бы
     * вводящим в заблуждение; для ИП эти поля остаются пустыми (не обязательны).
     *
     * @return array<int, array{name:string, inn:string, kpp:string, ogrn:string, legal_address:string, director_name:string, director_position:string, is_active:bool, address_display:string}>
     */
    public static function suggestParty(string $query): array
    {
        $response = self::request(self::PARTY_ENDPOINT, ['query' => $query, 'count' => 10]);

        if (! $response) {
            return [];
        }

        $suggestions = $response->json('suggestions') ?? [];

        return array_map(function (array $suggestion) {
            $data = $suggestion['data'] ?? [];
            $isLegal = ($data['type'] ?? null) === 'LEGAL';

            return [
                'name' => $data['name']['full_with_opf'] ?? $data['name']['short_with_opf'] ?? $suggestion['value'] ?? '',
                'inn' => $data['inn'] ?? '',
                'kpp' => $data['kpp'] ?? '',
                'ogrn' => $data['ogrn'] ?? '',
                'legal_address' => $data['address']['value'] ?? '',
                'director_name' => $isLegal ? ($data['management']['name'] ?? '') : '',
                'director_position' => $isLegal ? ($data['management']['post'] ?? '') : '',
                'is_active' => ($data['state']['status'] ?? null) === 'ACTIVE',
                'address_display' => $data['address']['value'] ?? '',
            ];
        }, $suggestions);
    }

    private static function request(string $endpoint, array $payload): ?Response
    {
        $token = tenancy()->central(fn () => PlatformSetting::get('dadata_api_key'));

        if (! $token) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Token '.$token,
            ])->timeout(5)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('DaData: ошибка запроса', ['endpoint' => $endpoint, 'message' => $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response : null;
    }
}

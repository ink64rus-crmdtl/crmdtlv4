<?php

namespace App\Services;

class CountryConfigService
{
    public static function getSupportedCountries(): array
    {
        return [
            'RU' => [
                'code' => 'RU',
                'name' => 'Россия',
                'currency' => 'RUB',
                'locale' => 'ru',
                'timezone' => 'Europe/Moscow',
                'phone_mask' => '+7 (999) 999-99-99',
                'phone_code' => '+7',
                'plate_regex' => '^[АВЕКМНОРСТУХa-vekmhortuxA-VEKMHORTUX]{1}\d{3}[АВЕКМНОРСТУХa-vekmhortuxA-VEKMHORTUX]{2}\d{2,3}$',
                'plate_placeholder' => 'А 000 АА 77',
                'bank_labels' => [
                    'bik' => 'БИК банка',
                    'account_number' => 'Расчетный счет (20 цифр)',
                    'corr_account' => 'Корреспондентский счет',
                ],
                'requisite_schema' => [
                    ['key' => 'inn', 'label' => 'ИНН', 'required' => true, 'type' => 'text', 'placeholder' => '10 или 12 цифр'],
                    ['key' => 'kpp', 'label' => 'КПП', 'required' => false, 'type' => 'text', 'placeholder' => '9 цифр (для ООО)'],
                    ['key' => 'ogrn', 'label' => 'ОГРН / ОГРНИП', 'required' => false, 'type' => 'text', 'placeholder' => '13 или 15 цифр'],
                    ['key' => 'legal_address', 'label' => 'Юридический адрес', 'required' => true, 'type' => 'textarea', 'placeholder' => 'г. Москва, ул...'],
                ],
                'vat' => ['applicable' => true, 'rates' => [20, 10, 0], 'default_rate' => 20, 'label' => 'НДС'],
            ],
            'BY' => [
                'code' => 'BY',
                'name' => 'Беларусь',
                'currency' => 'BYN',
                'locale' => 'ru',
                'timezone' => 'Europe/Minsk',
                'phone_mask' => '+375 (99) 999-99-99',
                'phone_code' => '+375',
                'plate_regex' => '^\d{4}\s?[A-Za-z]{2}-[1-7]$',
                'plate_placeholder' => '0000 AA-7',
                'bank_labels' => [
                    'bik' => 'BIC / БИК банка',
                    'account_number' => 'IBAN счет (BY28...)',
                    'corr_account' => 'Код банка',
                ],
                'requisite_schema' => [
                    ['key' => 'unp', 'label' => 'УНП (Учетный номер плательщика)', 'required' => true, 'type' => 'text', 'placeholder' => '9 цифр'],
                    ['key' => 'okpo', 'label' => 'ОКПО', 'required' => false, 'type' => 'text', 'placeholder' => '8 цифр'],
                    ['key' => 'legal_address', 'label' => 'Юридический адрес', 'required' => true, 'type' => 'textarea', 'placeholder' => 'г. Минск, ул...'],
                ],
                'vat' => ['applicable' => true, 'rates' => [20, 10, 0], 'default_rate' => 20, 'label' => 'НДС'],
            ],
            'KZ' => [
                'code' => 'KZ',
                'name' => 'Казахстан',
                'currency' => 'KZT',
                'locale' => 'ru',
                'timezone' => 'Asia/Almaty',
                'phone_mask' => '+7 (799) 999-99-99',
                'phone_code' => '+7',
                'plate_regex' => '^\d{3}\s?[A-Za-z]{2,3}\s?\d{2}$',
                'plate_placeholder' => '001 AAA 02',
                'bank_labels' => [
                    'bik' => 'БИК / SWIFT',
                    'account_number' => 'IBAN счет (KZ86...)',
                    'corr_account' => 'Кбе (Код бенефициара)',
                ],
                'requisite_schema' => [
                    ['key' => 'bin_iin', 'label' => 'БИН / ИИН', 'required' => true, 'type' => 'text', 'placeholder' => '12 цифр'],
                    ['key' => 'legal_address', 'label' => 'Юридический адрес', 'required' => true, 'type' => 'textarea', 'placeholder' => 'г. Алматы, пр...'],
                ],
                'vat' => ['applicable' => true, 'rates' => [12, 0], 'default_rate' => 12, 'label' => 'НДС'],
            ],
            'GE' => [
                'code' => 'GE',
                'name' => 'Грузия',
                'currency' => 'GEL',
                'locale' => 'en',
                'timezone' => 'Asia/Tbilisi',
                'phone_mask' => '+995 (599) 99-99-99',
                'phone_code' => '+995',
                'plate_regex' => '^[A-Za-z]{2}-\d{3}-[A-Za-z]{2}$',
                'plate_placeholder' => 'AA-000-AA',
                'bank_labels' => [
                    'bik' => 'SWIFT Code',
                    'account_number' => 'IBAN Account (GE29TB...)',
                    'corr_account' => 'Intermediary Bank',
                ],
                'requisite_schema' => [
                    ['key' => 'tax_id', 'label' => 'Identification Code (ИНН)', 'required' => true, 'type' => 'text', 'placeholder' => '9 or 11 digits'],
                    ['key' => 'legal_address', 'label' => 'Legal Address', 'required' => true, 'type' => 'textarea', 'placeholder' => 'Tbilisi, Rustaveli ave...'],
                ],
                'vat' => ['applicable' => true, 'rates' => [18, 0], 'default_rate' => 18, 'label' => 'НДС'],
            ],
        ];
    }

    public static function getForCountry(string $countryCode): ?array
    {
        $countryCode = strtoupper($countryCode);
        $countries = static::getSupportedCountries();
        $country = $countries[$countryCode] ?? $countries['RU'] ?? null;

        if ($country !== null) {
            $country['signatory_schema'] = static::signatorySchema();
        }

        return $country;
    }

    /**
     * Подписанты для печатных документов (Акт/Счёт/Договор — колонтитул
     * "Поставщик ___ подпись ___" — CLAUDE.md, документооборот) — те же поля
     * нужны и юрлицу самого тенанта (Settings/LegalEntities), и B2B-клиенту
     * (CRM/Clients, requisites — для договоров, где клиент сам сторона
     * сделки). НЕ часть requisite_schema (та — налоговые/регистрационные
     * номера, разные по странам) — эти поля одинаковы независимо от
     * юрисдикции, поэтому один список, а не дублирование в каждой стране.
     * Хранятся в том же свободном JSON requisites (LegalEntity/Client) —
     * DocumentPlaceholderService уже разворачивает ВСЕ его ключи как
     * {{legal_entity.*}}/{{client.*}}, дополнительный код для рендера не нужен.
     */
    public static function signatorySchema(): array
    {
        return [
            ['key' => 'director_position', 'label' => 'Должность руководителя', 'required' => false, 'type' => 'text', 'placeholder' => 'Генеральный директор'],
            ['key' => 'director_name', 'label' => 'ФИО руководителя', 'required' => false, 'type' => 'text', 'placeholder' => 'Иванов Иван Иванович'],
            ['key' => 'accountant_position', 'label' => 'Должность бухгалтера', 'required' => false, 'type' => 'text', 'placeholder' => 'Главный бухгалтер'],
            ['key' => 'accountant_name', 'label' => 'ФИО бухгалтера', 'required' => false, 'type' => 'text', 'placeholder' => 'Петрова Мария Сергеевна'],
        ];
    }
}

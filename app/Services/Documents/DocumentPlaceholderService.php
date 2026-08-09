<?php

namespace App\Services\Documents;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\LegalEntity;
use App\Models\Transaction;
use App\Models\WorkOrder;
use App\Services\CountryConfigService;
use Illuminate\Database\Eloquent\Model;

/**
 * Строит набор плейсхолдеров под конкретную запись — по аналогии с
 * App\Services\Messaging\MessageTemplateService::renderForX(), но с
 * добавлением табличных секций (тут они реально нужны — позиции заказа).
 * Реестр типов сущностей — App\Http\Controllers\Tenant\DocumentTemplateController::ENTITY_TYPES,
 * этот сервис только строит данные, ничего не знает о шаблонах/CRUD.
 */
class DocumentPlaceholderService
{
    /**
     * @return array{flat: array<string,string>, tables: array<string,array>, legal_entity: ?LegalEntity}
     */
    public static function buildFor(string $entityType, Model $entity): array
    {
        $legalEntity = self::resolveLegalEntity($entity);
        $branch = $entity->branch ?? null;

        $flat = array_merge(
            self::companyPlaceholders($legalEntity, $branch),
            match ($entityType) {
                'work_order' => self::workOrderPlaceholders($entity),
                'transaction' => self::transactionPlaceholders($entity),
                'client' => self::clientPlaceholders($entity),
                default => [],
            }
        );

        $tables = match ($entityType) {
            'work_order' => ['items' => self::workOrderItemRows($entity)],
            default => [],
        };

        return ['flat' => $flat, 'tables' => $tables, 'legal_entity' => $legalEntity];
    }

    /**
     * Приоритет — явный выбор на самой записи (WorkOrder.legal_entity_id —
     * точка теперь может иметь несколько юрлиц, branch_legal_entity, так что
     * "чьё юрлицо" однозначно не выводится). Фолбэк — юрлицо точки, но
     * ТОЛЬКО если оно у неё ровно одно: при 0 (точка работает без юрлица,
     * это осознанно допустимо) или 2+ (неоднозначно, запись должна была
     * явно выбрать) возвращаем null — DocumentGenerationService в этом
     * случае просто формирует документ с пустыми реквизитами, не падает.
     */
    public static function resolveLegalEntity(Model $entity): ?LegalEntity
    {
        if ($entity->legal_entity_id) {
            return LegalEntity::find($entity->legal_entity_id);
        }

        $branch = $entity->branch ?? null;
        if (!$branch) {
            return null;
        }

        $legalEntities = $branch->legalEntities;

        return $legalEntities->count() === 1 ? $legalEntities->first() : null;
    }

    private static function companyPlaceholders(?LegalEntity $legalEntity, ?Branch $branch): array
    {
        $placeholders = [];

        if ($legalEntity) {
            $placeholders['legal_entity.name'] = $legalEntity->name;
            $placeholders['legal_entity.tax_id'] = $legalEntity->tax_id ?? '';

            // requisites — свободный JSON, набор ключей зависит от страны
            // тенанта (App\Services\CountryConfigService::requisite_schema:
            // inn/kpp/ogrn/legal_address для RU и т.п.). Сначала заполняем
            // ВСЕ ключи схемы страны пустой строкой — иначе, например,
            // {{legal_entity.kpp}} у ИП (нет КПП, только у ООО) не попадёт
            // в $placeholders вообще, DocumentRenderer сочтёт его неизвестным
            // и заменит на пустую строку "молча" через отдельный safety-net
            // (тоже верно), но лучше, чтобы плейсхолдер СРАЗУ был в списке
            // подсказок на фронте и предсказуемо пустовал, а не был "то
            // есть, то нет" в зависимости от того, что реально лежит в JSON.
            $requisites = (array) $legalEntity->requisites;
            $schema = CountryConfigService::getForCountry((string) ($requisites['country_code'] ?? ''));

            // signatory_schema (должность/ФИО руководителя и бухгалтера) —
            // те же поля, что и requisite_schema, но не зависят от страны
            // (CountryConfigService::signatorySchema) — нужны в подписях
            // печатных форм (Акт/Счёт/Договор — CLAUDE.md, документооборот).
            foreach ([...($schema['requisite_schema'] ?? []), ...($schema['signatory_schema'] ?? [])] as $field) {
                $placeholders['legal_entity.' . $field['key']] = '';
            }

            foreach ($requisites as $key => $value) {
                $placeholders['legal_entity.' . $key] = (string) $value;
            }

            $account = Account::where('legal_entity_id', $legalEntity->id)
                ->where('is_default_for_invoicing', true)
                ->first();

            if ($account) {
                $placeholders['account.bank_name'] = $account->bank_name ?? '';
                $placeholders['account.bik'] = $account->bik ?? '';
                $placeholders['account.account_number'] = $account->account_number ?? '';
                $placeholders['account.corr_account'] = $account->corr_account ?? '';
            }
        }

        if ($branch) {
            $placeholders['branch.name'] = $branch->name;
            $placeholders['branch.address'] = $branch->address ?? '';
            $placeholders['branch.phone'] = $branch->phone ?? '';
        }

        return $placeholders;
    }

    private static function workOrderPlaceholders(WorkOrder $workOrder): array
    {
        return [
            'work_order.id' => (string) $workOrder->id,
            'work_order.total_amount' => self::money($workOrder->total_amount),
            'work_order.discount_amount' => self::money($workOrder->discount_amount),
            'work_order.final_amount' => self::money($workOrder->final_amount),
            // total_amount уже есть сумма items.total (см. WorkOrder::recalculateTotals) —
            // items_total просто более понятное имя для использования сразу после
            // табличной секции {{#items}}, без диссонанса "почему это work_order.*".
            'items_total' => self::money($workOrder->total_amount),
            'client.name' => $workOrder->client?->name ?? '',
            'client.phone' => $workOrder->client?->phone ?? '',
            'vehicle.plate_number' => $workOrder->vehicle?->plate_number ?? '',
            ...self::clientRequisitePlaceholders($workOrder->client),
        ];
    }

    private static function workOrderItemRows(WorkOrder $workOrder): array
    {
        return $workOrder->items->values()->map(fn ($item, $index) => [
            'item.index' => (string) ($index + 1),
            'item.name' => $item->name,
            'item.quantity' => rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.'),
            'item.price' => self::money($item->price),
            'item.discount_amount' => self::money($item->discount_amount),
            'item.total' => self::money($item->total),
        ])->all();
    }

    private static function transactionPlaceholders(Transaction $transaction): array
    {
        return [
            'transaction.id' => (string) $transaction->id,
            'transaction.amount' => self::money($transaction->amount),
            'transaction.type' => $transaction->type,
            'transaction.comment' => $transaction->comment ?? '',
            'transaction.date' => $transaction->transaction_date?->format('d.m.Y') ?? '',
        ];
    }

    private static function clientPlaceholders(Client $client): array
    {
        return [
            'client.name' => $client->name,
            'client.phone' => $client->phone ?? '',
            'client.email' => $client->email ?? '',
            ...self::clientRequisitePlaceholders($client),
        ];
    }

    /**
     * B2B-клиент — сам сторона договора (не только тенант): его реквизиты
     * (ИНН/КПП, должность и ФИО руководителя — CountryConfigService::
     * signatorySchema) нужны в печатной форме так же, как и у тенанта. У
     * B2C requisites — паспортные данные (другой набор ключей, форма в
     * CRM/Clients/Index.vue), разворачиваем как есть без привязки к схеме —
     * какие ключи реально лежат в JSON, такие и станут {{client.*}}.
     */
    private static function clientRequisitePlaceholders(?Client $client): array
    {
        if (!$client) {
            return [];
        }

        $placeholders = [];

        foreach ((array) $client->requisites as $key => $value) {
            $placeholders['client.' . $key] = (string) $value;
        }

        return $placeholders;
    }

    private static function money(int $amountInMinorUnits): string
    {
        return number_format($amountInMinorUnits / 100, 2, ',', ' ');
    }
}

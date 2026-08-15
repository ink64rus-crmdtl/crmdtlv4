<?php

namespace App\Services\Documents;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\LegalEntity;
use App\Models\Product;
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
                'goods_receipt' => self::goodsReceiptPlaceholders($entity),
                default => [],
            }
        );

        $tables = match ($entityType) {
            'work_order' => ['items' => self::workOrderItemRows($entity)],
            'goods_receipt' => ['items' => self::goodsReceiptItemRows($entity)],
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
        if (! $branch) {
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
                $placeholders['legal_entity.'.$field['key']] = '';
            }

            foreach ($requisites as $key => $value) {
                $placeholders['legal_entity.'.$key] = (string) $value;
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
        // Скидка на заказе разделена на два взаимоисключающих режима — общая
        // (work_order.discount_amount, колонка) и по позициям (сумма
        // item.discount_amount) — см. WorkOrderController::updateDiscount()/
        // addItem()/updateItem(). Документ не обязан знать, каким именно
        // способом менеджер задал скидку — плейсхолдер "Сумма скидки" в
        // итоговой части обязан показать её в любом случае. WorkOrder::
        // total_amount (DB-колонка) уже НЕТТО по позиционной скидке (см.
        // WorkOrderController::recalculateTotals(), item.total = qty*price -
        // item.discount_amount), поэтому для "Сумма позиций до скидки" здесь
        // считаем валовую сумму заново, а не берём готовую колонку — иначе
        // при позиционной скидке в документе "Сумма позиций - Скидка"
        // переставало бы сходиться с "Итого".
        $itemsDiscountTotal = $workOrder->items->sum('discount_amount');
        $grossTotal = $workOrder->items->sum(fn ($item) => (int) round($item->quantity * $item->price));
        $effectiveDiscount = $workOrder->discount_amount + $itemsDiscountTotal;

        return [
            'work_order.id' => (string) $workOrder->id,
            'work_order.total_amount' => self::money($grossTotal),
            'work_order.discount_amount' => self::money($effectiveDiscount),
            'work_order.final_amount' => self::money($workOrder->final_amount),
            // total_amount уже есть сумма items.total (см. WorkOrder::recalculateTotals) —
            // items_total просто более понятное имя для использования сразу после
            // табличной секции {{#items}}, без диссонанса "почему это work_order.*".
            'items_total' => self::money($grossTotal),
            // НДС — только если юрлицо заказа плательщик (WorkOrderController::
            // recalculateTotals() снепшотит ставку/сумму на сам заказ); если нет —
            // пустая строка, DocumentRenderer вырежет плейсхолдер из шаблона.
            'work_order.vat_rate' => $workOrder->vat_rate !== null ? $workOrder->vat_rate.'%' : '',
            'work_order.vat_amount' => $workOrder->vat_rate !== null ? self::money($workOrder->vat_amount) : '',
            'work_order.total_amount_without_vat' => $workOrder->vat_rate !== null ? self::money($workOrder->final_amount - $workOrder->vat_amount) : '',
            // Флаги-условия для {{#if ...}} в DocumentRenderer — не для
            // печати значением, а чтобы один шаблон верно показывал нужную
            // формулировку сразу в обоих режимах НДС (и никакой — для
            // неплательщика). Взаимоисключающие, поэтому без отрицания.
            'work_order.vat_inclusive' => ($workOrder->vat_rate !== null && $workOrder->vat_calculation_method === 'inclusive') ? '1' : '',
            'work_order.vat_exclusive' => ($workOrder->vat_rate !== null && $workOrder->vat_calculation_method === 'exclusive') ? '1' : '',
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
            // Флаг-условие для {{#if item.has_discount}} внутри {{#items}} (см.
            // DocumentRenderer::renderConditionals()) — так шаблон может
            // показывать колонку "Скидка" только у тех строк, где она реально
            // есть, не добавляя "0,00 ₽" на каждую позицию заказа.
            'item.has_discount' => $item->discount_amount > 0 ? '1' : '',
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
        if (! $client) {
            return [];
        }

        $placeholders = [];

        foreach ((array) $client->requisites as $key => $value) {
            $placeholders['client.'.$key] = (string) $value;
        }

        return $placeholders;
    }

    private static function goodsReceiptPlaceholders(GoodsReceipt $receipt): array
    {
        $vatTotal = (int) $receipt->items->sum('vat_amount');

        return [
            'goods_receipt.id' => (string) $receipt->id,
            'goods_receipt.date' => $receipt->receipt_date?->format('d.m.Y') ?? '',
            'goods_receipt.supplier_document_number' => $receipt->supplier_document_number ?? '',
            'goods_receipt.total_value' => self::money($receipt->total_value),
            'items_total' => self::money($receipt->total_value),
            // НДС считается по каждой позиции (не по накладной целиком —
            // у разных товаров от одного поставщика в теории могут быть
            // разные ставки), поэтому здесь только суммарная сумма налога,
            // без единой ставки; ставка — только в табличной секции item.*.
            'goods_receipt.vat_amount' => $vatTotal > 0 ? self::money($vatTotal) : '',
            'goods_receipt.total_value_without_vat' => $vatTotal > 0 ? self::money($receipt->total_value - $vatTotal) : '',
            // Флаги-условия — см. пояснение у work_order.vat_inclusive выше.
            'goods_receipt.vat_inclusive' => ($vatTotal > 0 && $receipt->vat_calculation_method === 'inclusive') ? '1' : '',
            'goods_receipt.vat_exclusive' => ($vatTotal > 0 && $receipt->vat_calculation_method === 'exclusive') ? '1' : '',
            'supplier.name' => $receipt->supplier?->name ?? '',
            'supplier.phone' => $receipt->supplier?->phone ?? '',
            ...self::supplierRequisitePlaceholders($receipt->supplier),
        ];
    }

    private static function goodsReceiptItemRows(GoodsReceipt $receipt): array
    {
        return $receipt->items->values()->map(fn ($item, $index) => [
            'item.index' => (string) ($index + 1),
            'item.name' => self::localizedProductName($item->product),
            'item.quantity' => rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.'),
            'item.price' => self::money($item->cost_price),
            'item.total' => self::money((int) round($item->quantity * $item->cost_price)),
            'item.vat_rate' => $item->vat_rate !== null ? $item->vat_rate.'%' : '',
            'item.vat_amount' => $item->vat_rate !== null ? self::money($item->vat_amount) : '',
            // Флаг-условие — по позиции, а не по накладной целиком: у одного
            // поставщика товары технически бывают на разных ставках/без НДС.
            'item.has_vat' => $item->vat_rate !== null ? '1' : '',
        ])->all();
    }

    /**
     * Поставщик в накладной — тоже Client (та же роль-система, что и
     * подрядчик, см. CLAUDE.md), но плейсхолдеры под своим префиксом
     * supplier.*, а не client.* — иначе смысл поля путается в шаблоне
     * приходной накладной ("Покупатель"/"Поставщик" — разные стороны).
     */
    private static function supplierRequisitePlaceholders(?Client $supplier): array
    {
        if (! $supplier) {
            return [];
        }

        $placeholders = [];

        foreach ((array) $supplier->requisites as $key => $value) {
            $placeholders['supplier.'.$key] = (string) $value;
        }

        return $placeholders;
    }

    /**
     * Product.name — spatie/laravel-translatable (CLAUDE.md, п.6). Тот же
     * защитный паттерн, что и App\Jobs\ExportEntitiesJob (is_array-проверка):
     * в зависимости от контекста загрузки атрибут отдаёт либо уже
     * отрезолвленную под текущую локаль строку, либо сырой массив локалей.
     */
    private static function localizedProductName(?Product $product): string
    {
        if (! $product) {
            return '';
        }

        $name = $product->name;

        return is_array($name) ? ($name['ru'] ?? current($name) ?: '') : (string) $name;
    }

    private static function money(int $amountInMinorUnits): string
    {
        return number_format($amountInMinorUnits / 100, 2, ',', ' ');
    }
}

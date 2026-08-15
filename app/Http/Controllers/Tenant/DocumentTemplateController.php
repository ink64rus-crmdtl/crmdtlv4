<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\CustomFieldPlaceholderService;
use App\Services\Documents\DocxToHtmlConverter;
use App\Services\Documents\PlatformDocumentTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Фаза 12 — шаблоны печатных документов. entity_type зашит списком (как
 * MessageTemplateController::TRIGGERS) — сущность должна реально иметь
 * билдер плейсхолдеров в App\Services\Documents\DocumentPlaceholderService,
 * иначе шаблон нечем будет наполнить. Список сущностей — сознательно
 * ограниченное ядро (Заказ/Транзакция/Клиент), расширяется добавлением
 * записи сюда + метода buildForX() в DocumentPlaceholderService, без
 * структурных изменений.
 *
 * format=docx — тело шаблона получено конвертацией загруженного .docx
 * (App\Services\Documents\DocxToHtmlConverter), один раз, здесь, при
 * сохранении — после этого хранится и рендерится как обычный html-шаблон,
 * см. app/Models/DocumentTemplate.php.
 */
class DocumentTemplateController extends Controller
{
    public const ENTITY_TYPES = [
        'work_order' => 'Заказ-наряд',
        'transaction' => 'Транзакция',
        'client' => 'Клиент',
        'goods_receipt' => 'Приходная накладная',
    ];

    /**
     * key => человекочитаемое пояснение. Общие для всех типов (реквизиты
     * юрлица/точки) + специфичные для конкретной сущности — держим рядом
     * с реестром типов, чтобы подсказка плейсхолдеров на фронте не
     * расходилась с тем, что реально строит DocumentPlaceholderService.
     */
    public const COMMON_PLACEHOLDERS = [
        'document.number' => 'Номер этого документа (присваивается автоматически при формировании)',
        'document.date' => 'Дата формирования документа',
        'legal_entity.name' => 'Название юрлица',
        'legal_entity.tax_id' => 'ИНН/УНП (общее поле налогового номера)',
        'legal_entity.inn' => 'ИНН (РФ)',
        'legal_entity.kpp' => 'КПП (РФ, для ООО)',
        'legal_entity.legal_address' => 'Юридический адрес',
        'legal_entity.director_position' => 'Должность руководителя (для подписи "Поставщик")',
        'legal_entity.director_name' => 'ФИО руководителя (для подписи "Поставщик")',
        'legal_entity.accountant_position' => 'Должность бухгалтера',
        'legal_entity.accountant_name' => 'ФИО бухгалтера',
        'account.bank_name' => 'Название банка (счёт по умолчанию для документов)',
        'account.bik' => 'БИК банка',
        'account.account_number' => 'Расчётный счёт',
        'account.corr_account' => 'Корреспондентский счёт',
        'branch.name' => 'Название локации',
        'branch.address' => 'Адрес локации',
        'branch.phone' => 'Телефон локации',
    ];

    public const ENTITY_PLACEHOLDERS = [
        'work_order' => [
            'work_order.id' => 'Номер заказа-наряда',
            'work_order.total_amount' => 'Сумма позиций до скидки',
            'work_order.discount_amount' => 'Сумма скидки',
            'work_order.final_amount' => 'Итоговая сумма к оплате',
            'items_total' => 'Сумма позиций (то же, что work_order.total_amount)',
            'work_order.vat_rate' => 'Ставка НДС (пусто, если юрлицо заказа не плательщик НДС)',
            'work_order.vat_amount' => 'Сумма НДС',
            'work_order.total_amount_without_vat' => 'Итоговая сумма без НДС',
            'client.name' => 'Имя клиента',
            'client.phone' => 'Телефон клиента',
            'vehicle.plate_number' => 'Госномер автомобиля',
            ...self::CLIENT_ORGANIZATION_PLACEHOLDERS,
        ],
        'transaction' => [
            'transaction.id' => 'Номер транзакции',
            'transaction.amount' => 'Сумма транзакции',
            'transaction.type' => 'Тип (income/expense)',
            'transaction.comment' => 'Комментарий к транзакции',
            'transaction.date' => 'Дата транзакции',
        ],
        'client' => [
            'client.name' => 'Имя клиента',
            'client.phone' => 'Телефон клиента',
            'client.email' => 'Email клиента',
            ...self::CLIENT_ORGANIZATION_PLACEHOLDERS,
        ],
        'goods_receipt' => [
            'goods_receipt.id' => 'Номер накладной',
            'goods_receipt.date' => 'Дата поступления товара',
            'goods_receipt.supplier_document_number' => 'Номер накладной поставщика (их бумага)',
            'goods_receipt.total_value' => 'Сумма закупки по накладной',
            'items_total' => 'Сумма закупки (то же, что goods_receipt.total_value)',
            'goods_receipt.vat_amount' => 'Сумма НДС по накладной (пусто, если НДС не применялся ни к одной позиции)',
            'goods_receipt.total_value_without_vat' => 'Сумма закупки без НДС',
            'supplier.name' => 'Имя/название поставщика',
            'supplier.phone' => 'Телефон поставщика',
            ...self::SUPPLIER_ORGANIZATION_PLACEHOLDERS,
        ],
    ];

    /**
     * Актуальны только для B2B-клиентов (Client.type=b2b) — реквизиты и
     * подписанты хранятся в том же свободном requisites JSON, что и у
     * LegalEntity (см. CountryConfigService::signatorySchema). Для B2C
     * клиента эти плейсхолдеры просто останутся пустыми в готовом документе
     * (DocumentRenderer вырезает неподставленные {{ключ}}, см. п.12.5).
     */
    private const CLIENT_ORGANIZATION_PLACEHOLDERS = [
        'client.inn' => 'ИНН клиента-организации (только для B2B)',
        'client.kpp' => 'КПП клиента-организации (только для B2B)',
        'client.legal_address' => 'Юридический адрес клиента-организации (только для B2B)',
        'client.director_position' => 'Должность руководителя клиента-организации (для подписи "Покупатель")',
        'client.director_name' => 'ФИО руководителя клиента-организации (для подписи "Покупатель")',
    ];

    /**
     * Поставщик — тот же Client, что и в CLIENT_ORGANIZATION_PLACEHOLDERS,
     * но в накладной он не "покупатель", а сторона-продавец — реквизиты
     * подмешиваются под своим префиксом supplier.*, а не client.*, чтобы не
     * путать роли в шаблоне приходной накладной (DocumentPlaceholderService::
     * goodsReceiptPlaceholders() строит их из того же requisites JSON).
     */
    private const SUPPLIER_ORGANIZATION_PLACEHOLDERS = [
        'supplier.inn' => 'ИНН поставщика (только для B2B)',
        'supplier.kpp' => 'КПП поставщика (только для B2B)',
        'supplier.legal_address' => 'Юридический адрес поставщика (только для B2B)',
        'supplier.director_position' => 'Должность руководителя поставщика (для подписи "Поставщик" в акте)',
        'supplier.director_name' => 'ФИО руководителя поставщика (для подписи "Поставщик" в акте)',
    ];

    public const ENTITY_TABLE_PLACEHOLDERS = [
        'work_order' => [
            'section' => 'items',
            'fields' => [
                'item.index' => 'Порядковый номер строки (1, 2, 3…)',
                'item.name' => 'Наименование позиции',
                'item.quantity' => 'Количество',
                'item.price' => 'Цена за единицу',
                'item.discount_amount' => 'Скидка по позиции',
                'item.total' => 'Сумма по позиции',
            ],
            'conditions' => [
                'item.has_discount' => 'Блок покажется, только если на эту позицию задана индивидуальная скидка',
            ],
        ],
        'goods_receipt' => [
            'section' => 'items',
            'fields' => [
                'item.index' => 'Порядковый номер строки (1, 2, 3…)',
                'item.name' => 'Наименование товара',
                'item.quantity' => 'Количество',
                'item.price' => 'Цена закупки за единицу',
                'item.total' => 'Сумма по позиции',
                'item.vat_rate' => 'Ставка НДС по позиции (пусто, если НДС не применялся)',
                'item.vat_amount' => 'Сумма НДС по позиции',
            ],
            'conditions' => [
                'item.has_vat' => 'Блок покажется, только если НДС применялся именно к этой позиции',
            ],
        ],
    ];

    /**
     * Флаги-условия для {{#if key}}...{{/if}} (App\Services\Documents\
     * DocumentRenderer) — НЕ значения для печати, а переключатели видимости
     * куска шаблона. Держим отдельным реестром от ENTITY_PLACEHOLDERS, чтобы
     * фронт мог отрисовать их отдельной секцией с другим действием по клику
     * (обернуть выделенный текст, а не вставить значение).
     */
    public const ENTITY_CONDITIONS = [
        'work_order' => [
            'work_order.vat_inclusive' => 'Блок покажется, только если у юрлица заказа НДС включён в цену ("в т.ч.")',
            'work_order.vat_exclusive' => 'Блок покажется, только если у юрлица заказа НДС начисляется сверх суммы',
        ],
        'goods_receipt' => [
            'goods_receipt.vat_inclusive' => 'Блок покажется, только если НДС по накладной включён в цену',
            'goods_receipt.vat_exclusive' => 'Блок покажется, только если НДС по накладной начисляется сверх суммы',
        ],
    ];

    public function index(): Response
    {
        return Inertia::render('Settings/DocumentTemplates/Index', [
            'templates' => DocumentTemplate::with('media')->orderBy('id', 'desc')->get()
                ->map(function (DocumentTemplate $t) {
                    $data = $t->toArray();
                    $data['source_file_name'] = $t->getFirstMedia('source_file')?->file_name;

                    return $data;
                }),
            'entityTypes' => self::ENTITY_TYPES,
            'commonPlaceholders' => self::COMMON_PLACEHOLDERS,
            'entityPlaceholders' => $this->entityPlaceholdersWithCustomFields(),
            'entityTablePlaceholders' => self::ENTITY_TABLE_PLACEHOLDERS,
            'entityConditions' => self::ENTITY_CONDITIONS,
        ]);
    }

    /**
     * ENTITY_PLACEHOLDERS дополняется кастомными полями (CustomFieldDefinition.
     * use_in_templates, CLAUDE.md §5) — теми же ключами {{<entity>.custom.
     * <key>}}, что реально подставляет DocumentPlaceholderService::buildFor().
     * Для work_order добавляются ещё и подсказки вложенных client/vehicle —
     * их плейсхолдеры (client.name/vehicle.plate_number) уже доступны в
     * заказе-наряде, поэтому и их кастомные поля должны быть видны в пикере.
     *
     * @return array<string,array<string,string>>
     */
    private function entityPlaceholdersWithCustomFields(): array
    {
        $entityPlaceholders = self::ENTITY_PLACEHOLDERS;

        foreach ($entityPlaceholders as $entityType => $placeholders) {
            $entityPlaceholders[$entityType] = array_merge($placeholders, CustomFieldPlaceholderService::hintsFor($entityType));
        }

        if (isset($entityPlaceholders['work_order'])) {
            $entityPlaceholders['work_order'] = array_merge(
                $entityPlaceholders['work_order'],
                CustomFieldPlaceholderService::hintsFor('client'),
                CustomFieldPlaceholderService::hintsFor('vehicle')
            );
        }

        return $entityPlaceholders;
    }

    public function store(Request $request)
    {
        $validated = $this->validateTemplate($request, null);

        $template = DocumentTemplate::create($validated);

        if ($request->hasFile('source_file')) {
            $this->attachDocx($template, $request);
        }

        return redirect()->back()->with('success', 'Шаблон создан');
    }

    public function update(Request $request, DocumentTemplate $documentTemplate)
    {
        $validated = $this->validateTemplate($request, $documentTemplate);

        $documentTemplate->update($validated);

        if ($request->hasFile('source_file')) {
            $this->attachDocx($documentTemplate, $request);
        }

        return redirect()->back()->with('success', 'Шаблон обновлён');
    }

    public function destroy(DocumentTemplate $documentTemplate)
    {
        $documentTemplate->delete();

        return redirect()->back()->with('success', 'Шаблон удалён');
    }

    /**
     * Библиотека эталонных шаблонов платформы (central БД) под страну
     * текущего тенанта + «общие для всех стран» — App\Services\Documents\
     * PlatformDocumentTemplateService, JSON-эндпоинт для модалки на фронте
     * (тот же паттерн, что AccountController::lookupBik()).
     */
    public function library()
    {
        return response()->json(['templates' => PlatformDocumentTemplateService::listForCurrentTenant()]);
    }

    /**
     * Копирует библиотечный шаблон себе как стартовую точку — снепшот, не
     * живая ссылка, дальше это обычный тенантский DocumentTemplate.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'platform_document_template_id' => ['required', 'integer'],
        ]);

        PlatformDocumentTemplateService::import($validated['platform_document_template_id']);

        return redirect()->back()->with('success', 'Шаблон импортирован — можно редактировать как обычный');
    }

    /**
     * Конвертирует загруженный .docx в HTML (App\Services\Documents\
     * DocxToHtmlConverter) и сохраняет и результат (body), и оригинальный
     * файл (коллекция source_file — для скачивания/повторной загрузки).
     */
    private function attachDocx(DocumentTemplate $template, Request $request): void
    {
        $file = $request->file('source_file');

        try {
            $html = DocxToHtmlConverter::convert($file->getRealPath());
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'source_file' => 'Не удалось прочитать .docx-файл: '.$e->getMessage(),
            ]);
        }

        $template->update(['body' => $html]);
        $template->addMedia($file)->toMediaCollection('source_file');
    }

    private function validateTemplate(Request $request, ?DocumentTemplate $template): array
    {
        $isCreating = $template === null;
        $format = $request->input('format', 'html');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', 'string', Rule::in(array_keys(self::ENTITY_TYPES))],
            'format' => ['required', 'string', Rule::in(['html', 'docx'])],
            'number_prefix' => ['nullable', 'string', 'max:20'],
            'number_reset_yearly' => ['boolean'],
            'is_active' => ['boolean'],
        ];

        if ($format === 'html') {
            $rules['body'] = ['required', 'string', 'max:65535'];
        } else {
            // При редактировании файл не обязателен повторно — старая
            // конвертация (body) остаётся, пока не загрузят замену.
            $rules['source_file'] = [Rule::requiredIf($isCreating), 'nullable', 'file', 'mimes:docx', 'max:10240'];
            // body для format=docx не приходит с фронта вообще — заполняется
            // attachDocx() из конвертации, здесь просто не требуем его.
        }

        $validated = $request->validate($rules);
        unset($validated['source_file']);

        if ($format === 'docx' && $isCreating) {
            $validated['body'] = ''; // временно, attachDocx() перезапишет сразу после create()
        } elseif ($format === 'docx') {
            unset($validated['body']);
        }

        return $validated;
    }
}

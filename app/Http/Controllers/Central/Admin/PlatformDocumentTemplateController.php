<?php

namespace App\Http\Controllers\Central\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformDocumentTemplate;
use App\Services\CountryConfigService;
use App\Services\Documents\DocxToHtmlConverter;
use App\Services\Documents\PlatformDocumentTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Библиотека эталонных шаблонов документов по странам (central БД,
 * администратор платформы заводит их здесь) — тенант копирует понравившийся
 * себе как стартовую точку через App\Services\Documents\
 * PlatformDocumentTemplateService::import() (снепшот, без живой связи).
 *
 * Реестр плейсхолдеров/условий (ENTITY_TYPES/COMMON_PLACEHOLDERS/
 * ENTITY_PLACEHOLDERS/ENTITY_TABLE_PLACEHOLDERS/ENTITY_CONDITIONS) —
 * СОЗНАТЕЛЬНО продублирован из App\Http\Controllers\Tenant\
 * DocumentTemplateController, а не вынесен в общий класс: тот контроллер
 * уже сегодня явно референсится из Tenant\DocumentController::ENTITY_TYPES
 * (живой код), рефакторинг общего реестра ради этой задачи — лишний риск
 * регрессии в уже работающем тенантском коде. Если вокабуляр разъедется —
 * синхронизировать руками при следующей правке.
 */
class PlatformDocumentTemplateController extends Controller
{
    public const ENTITY_TYPES = [
        'work_order' => 'Заказ-наряд',
        'transaction' => 'Транзакция',
        'client' => 'Клиент',
        'goods_receipt' => 'Приходная накладная',
        'employee' => 'Сотрудник',
    ];

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
        'employee' => [
            'employee.id' => 'Табельный номер сотрудника',
            'employee.full_name' => 'ФИО полностью (Фамилия Имя Отчество)',
            'employee.last_name' => 'Фамилия',
            'employee.first_name' => 'Имя',
            'employee.middle_name' => 'Отчество',
            'employee.phone' => 'Телефон',
            'employee.personal_email' => 'Личный email',
            'employee.position' => 'Основная должность',
            'employee.secondary_position' => 'Совмещаемая должность',
            'employee.type_label' => 'Тип оформления («В штате» / «Самозанятый»)',
            'employee.birth_date' => 'Дата рождения',
            'employee.hire_date' => 'Дата приёма на работу',
            'employee.termination_date' => 'Дата увольнения',
            'employee.passport_series' => 'Серия паспорта',
            'employee.passport_number' => 'Номер паспорта',
            'employee.passport_issued_by' => 'Кем выдан паспорт',
            'employee.passport_issue_date' => 'Дата выдачи паспорта',
            'employee.passport_department_code' => 'Код подразделения',
            'employee.registration_address' => 'Адрес регистрации',
            'employee.salary_amount' => 'Оклад (в месяц)',
            'employee.self_employed_tax_percent' => 'Компенсация налога самозанятого',
        ],
    ];

    private const CLIENT_ORGANIZATION_PLACEHOLDERS = [
        'client.inn' => 'ИНН клиента-организации (только для B2B)',
        'client.kpp' => 'КПП клиента-организации (только для B2B)',
        'client.legal_address' => 'Юридический адрес клиента-организации (только для B2B)',
        'client.director_position' => 'Должность руководителя клиента-организации (для подписи "Покупатель")',
        'client.director_name' => 'ФИО руководителя клиента-организации (для подписи "Покупатель")',
    ];

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

    public const ENTITY_CONDITIONS = [
        'work_order' => [
            'work_order.vat_inclusive' => 'Блок покажется, только если у юрлица заказа НДС включён в цену ("в т.ч.")',
            'work_order.vat_exclusive' => 'Блок покажется, только если у юрлица заказа НДС начисляется сверх суммы',
        ],
        'goods_receipt' => [
            'goods_receipt.vat_inclusive' => 'Блок покажется, только если НДС по накладной включён в цену',
            'goods_receipt.vat_exclusive' => 'Блок покажется, только если НДС по накладной начисляется сверх суммы',
        ],
        'employee' => [
            'employee.is_staff' => 'Блок покажется, только если сотрудник оформлен в штат',
            'employee.is_self_employed' => 'Блок покажется, только если сотрудник — самозанятый',
            'employee.has_secondary_position' => 'Блок покажется, только если у сотрудника есть совмещаемая должность',
            'employee.has_termination_date' => 'Блок покажется, только если у сотрудника указана дата увольнения',
            'employee.is_active' => 'Блок покажется, только если сотрудник активен',
        ],
    ];

    public function index(): Response
    {
        return Inertia::render('Central/Admin/DocumentTemplates/Index', [
            'templates' => PlatformDocumentTemplate::with('media')->orderBy('id', 'desc')->get()
                ->map(function (PlatformDocumentTemplate $t) {
                    $data = $t->toArray();
                    $data['source_file_name'] = $t->getFirstMedia('source_file')?->file_name;

                    return $data;
                }),
            'countries' => CountryConfigService::getSupportedCountries(),
            'entityTypes' => self::ENTITY_TYPES,
            'commonPlaceholders' => self::COMMON_PLACEHOLDERS,
            'entityPlaceholders' => self::ENTITY_PLACEHOLDERS,
            'entityTablePlaceholders' => self::ENTITY_TABLE_PLACEHOLDERS,
            'entityConditions' => self::ENTITY_CONDITIONS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTemplate($request, null);

        $template = PlatformDocumentTemplate::create($validated);

        if ($request->hasFile('source_file')) {
            $this->attachDocx($template, $request);
        }

        return redirect()->back()->with('success', 'Шаблон создан');
    }

    public function update(Request $request, PlatformDocumentTemplate $platformDocumentTemplate)
    {
        $validated = $this->validateTemplate($request, $platformDocumentTemplate);

        $platformDocumentTemplate->update($validated);

        if ($request->hasFile('source_file')) {
            $this->attachDocx($platformDocumentTemplate, $request);
        }

        return redirect()->back()->with('success', 'Шаблон обновлён');
    }

    public function destroy(PlatformDocumentTemplate $platformDocumentTemplate)
    {
        $platformDocumentTemplate->delete();

        return redirect()->back()->with('success', 'Шаблон удалён');
    }

    /**
     * Предпросмотр — рендер тела шаблона БЕЗ привязки к реальной записи
     * (central-контекст в принципе не видит тенантские WorkOrder/Client и
     * т.п., см. докблок класса). Возвращает готовый HTML JSON'ом, фронт
     * показывает его в изолированном <iframe sandbox>.
     */
    public function preview(PlatformDocumentTemplate $platformDocumentTemplate)
    {
        return response()->json(['html' => PlatformDocumentTemplateService::renderPreview($platformDocumentTemplate)]);
    }

    private function attachDocx(PlatformDocumentTemplate $template, Request $request): void
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

    private function validateTemplate(Request $request, ?PlatformDocumentTemplate $template): array
    {
        $isCreating = $template === null;
        $format = $request->input('format', 'html');

        $rules = [
            'country_code' => ['nullable', 'string', 'size:2', Rule::in(array_keys(CountryConfigService::getSupportedCountries()))],
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', 'string', Rule::in(array_keys(self::ENTITY_TYPES))],
            'format' => ['required', 'string', Rule::in(['html', 'docx'])],
            'is_active' => ['boolean'],
        ];

        if ($format === 'html') {
            $rules['body'] = ['required', 'string', 'max:65535'];
        } else {
            $rules['source_file'] = [Rule::requiredIf($isCreating), 'nullable', 'file', 'mimes:docx', 'max:10240'];
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

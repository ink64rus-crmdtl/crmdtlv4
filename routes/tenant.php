<?php

use App\Http\Controllers\DumpController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Tenant\AccountController;
use App\Http\Controllers\Tenant\AccountSnapshotController;
use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\BusinessDirectionController;
use App\Http\Controllers\Tenant\ChannelController;
use App\Http\Controllers\Tenant\ChatController;
use App\Http\Controllers\Tenant\ClientController;
use App\Http\Controllers\Tenant\CommunicationsController;
use App\Http\Controllers\Tenant\CrmSettingsController;
use App\Http\Controllers\Tenant\CustomFieldController;
use App\Http\Controllers\Tenant\DadataController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DealController;
use App\Http\Controllers\Tenant\DictionaryController;
use App\Http\Controllers\Tenant\DocumentController;
use App\Http\Controllers\Tenant\DocumentTemplateController;
use App\Http\Controllers\Tenant\EmployeeController;
use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\LegalEntityController;
use App\Http\Controllers\Tenant\ListViewController;
use App\Http\Controllers\Tenant\LookupController;
use App\Http\Controllers\Tenant\LoyaltySettingsController;
use App\Http\Controllers\Tenant\MessageTemplateController;
use App\Http\Controllers\Tenant\MessengerWebhookController;
use App\Http\Controllers\Tenant\NotificationController;
use App\Http\Controllers\Tenant\PayrollController;
use App\Http\Controllers\Tenant\PayrollSettingsController;
use App\Http\Controllers\Tenant\PeriodClosureController;
use App\Http\Controllers\Tenant\PipelineSettingsController;
use App\Http\Controllers\Tenant\PositionController;
use App\Http\Controllers\Tenant\PostController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\RolePermissionController;
use App\Http\Controllers\Tenant\ServiceController;
use App\Http\Controllers\Tenant\StockBalanceController;
use App\Http\Controllers\Tenant\StockMovementController;
use App\Http\Controllers\Tenant\SystemController;
use App\Http\Controllers\Tenant\TaskController;
use App\Http\Controllers\Tenant\TransactionCategoryController;
use App\Http\Controllers\Tenant\TransactionController;
use App\Http\Controllers\Tenant\VehicleController;
use App\Http\Controllers\Tenant\WarehouseSettingsController;
use App\Http\Controllers\Tenant\WorkOrderController;
use App\Http\Middleware\EnsureWarehouseEnabled;
use App\Http\Middleware\PreventSandboxTenantHttpAccess;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventSandboxTenantHttpAccess::class,
    PreventAccessFromCentralDomains::class,
    SetBranchContext::class,
])->group(function () {

    // Вебхуки мессенджер-провайдеров — без auth (внешний сервис не залогинен),
    // но внутри tenancy-группы (домен тенанта резолвится по хосту как обычно),
    // защита — непубличный webhook_token в URL, не сессия/CSRF (см. bootstrap/app.php).
    Route::post('/webhooks/{provider}/{webhookToken}', [MessengerWebhookController::class, 'handle'])
        ->name('webhooks.messenger');

    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });

    Route::middleware('auth')->group(function () {
        // Регистрируем здесь (а не через withRouting(channels:...) в bootstrap/app.php) —
        // POST /broadcasting/auth обязан идти через InitializeTenancyByDomain, иначе
        // Broadcast::channel()-коллбэки в routes/channels.php бьют не в ту БД. См. комментарий
        // в bootstrap/app.php.
        Broadcast::routes();
        require base_path('routes/channels.php');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // Уведомления и экспорт
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::get('/exports/download/{filename}', [NotificationController::class, 'downloadExport'])->name('exports.download');

        // Системный раздел (Логи и Дамп)
        Route::get('/system', [SystemController::class, 'index'])->name('system.index');
        Route::get('/dump', [DumpController::class, 'download'])->name('dump');

        // Сохранение настроек таблиц (List Views)
        Route::post('/list-views', [ListViewController::class, 'store'])->name('list-views.store');

        // Главный маршрут настроек (редирект на Юрлица)
        Route::get('/settings', function () {
            return redirect()->route('settings.legal-entities.index');
        })->name('settings');

        // Настройки: Юридические лица
        Route::get('/settings/legal-entities', [LegalEntityController::class, 'index'])->name('settings.legal-entities.index');
        Route::post('/settings/legal-entities', [LegalEntityController::class, 'store'])->name('settings.legal-entities.store');
        Route::put('/settings/legal-entities/{legalEntity}', [LegalEntityController::class, 'update'])->name('settings.legal-entities.update');
        Route::delete('/settings/legal-entities/{legalEntity}', [LegalEntityController::class, 'destroy'])->name('settings.legal-entities.destroy');
        Route::post('/settings/legal-entities/bulk-delete', [LegalEntityController::class, 'bulkDestroy'])->name('settings.legal-entities.bulk-destroy');
        Route::post('/settings/legal-entities/bulk-export', [LegalEntityController::class, 'bulkExport'])->name('settings.legal-entities.bulk-export');

        // DaData: общий поиск организаций (не привязан к конкретному разделу —
        // используется и юрлицами тенанта, и реквизитами B2B-клиентов)
        Route::get('/dadata/party-suggest', [DadataController::class, 'suggestParty'])->name('dadata.party-suggest');

        // Настройки: Счета
        Route::get('/settings/accounts/bik-lookup', [AccountController::class, 'lookupBik'])->name('settings.accounts.bik-lookup');
        Route::post('/settings/accounts', [AccountController::class, 'store'])->name('settings.accounts.store');
        Route::put('/settings/accounts/{account}', [AccountController::class, 'update'])->name('settings.accounts.update');
        Route::delete('/settings/accounts/{account}', [AccountController::class, 'destroy'])->name('settings.accounts.destroy');

        // Настройки: Филиалы
        Route::get('/settings/branches', [BranchController::class, 'index'])->name('settings.branches.index');
        Route::post('/settings/branches', [BranchController::class, 'store'])->name('settings.branches.store');
        Route::put('/settings/branches/{branch}', [BranchController::class, 'update'])->name('settings.branches.update');
        Route::delete('/settings/branches/{branch}', [BranchController::class, 'destroy'])->name('settings.branches.destroy');
        Route::post('/settings/branches/bulk-delete', [BranchController::class, 'bulkDestroy'])->name('settings.branches.bulk-destroy');
        Route::post('/settings/branches/bulk-export', [BranchController::class, 'bulkExport'])->name('settings.branches.bulk-export');
        Route::get('/settings/branches/{branch}/logo', [BranchController::class, 'logo'])->name('settings.branches.logo');

        // Переключение контекста
        Route::post('/legal-entities/switch/{legalEntity?}', [LegalEntityController::class, 'switch'])->name('legal-entities.switch');
        Route::post('/branches/switch/{branch?}', [BranchController::class, 'switch'])->name('branches.switch');

        // Настройки: Направления бизнеса
        Route::get('/settings/business-directions', [BusinessDirectionController::class, 'index'])->name('settings.business-directions.index');
        Route::post('/settings/business-directions', [BusinessDirectionController::class, 'store'])->name('settings.business-directions.store');
        Route::put('/settings/business-directions/{businessDirection}', [BusinessDirectionController::class, 'update'])->name('settings.business-directions.update');
        Route::delete('/settings/business-directions/{businessDirection}', [BusinessDirectionController::class, 'destroy'])->name('settings.business-directions.destroy');
        Route::post('/settings/business-directions/bulk-delete', [BusinessDirectionController::class, 'bulkDestroy'])->name('settings.business-directions.bulk-destroy');
        Route::post('/settings/business-directions/bulk-export', [BusinessDirectionController::class, 'bulkExport'])->name('settings.business-directions.bulk-export');

        Route::get('/settings/posts', [PostController::class, 'index'])->name('settings.posts.index');
        Route::post('/settings/posts', [PostController::class, 'store'])->name('settings.posts.store');
        Route::put('/settings/posts/{post}', [PostController::class, 'update'])->name('settings.posts.update');
        Route::delete('/settings/posts/{post}', [PostController::class, 'destroy'])->name('settings.posts.destroy');
        Route::post('/settings/posts/bulk-delete', [PostController::class, 'bulkDestroy'])->name('settings.posts.bulk-destroy');
        Route::post('/settings/posts/reorder', [PostController::class, 'reorder'])->name('settings.posts.reorder');

        // Настройки: Склад
        Route::get('/settings/warehouse', [WarehouseSettingsController::class, 'index'])->name('settings.warehouse.index');
        Route::post('/settings/warehouse', [WarehouseSettingsController::class, 'store'])->name('settings.warehouse.store');
        Route::post('/settings/warehouses', [WarehouseSettingsController::class, 'storeWarehouse'])->name('settings.warehouses.store');
        Route::put('/settings/warehouses/{warehouse}', [WarehouseSettingsController::class, 'updateWarehouse'])->name('settings.warehouses.update');
        Route::delete('/settings/warehouses/{warehouse}', [WarehouseSettingsController::class, 'destroyWarehouse'])->name('settings.warehouses.destroy');

        // Настройки: Кастомные поля
        Route::get('/settings/custom-fields', [CustomFieldController::class, 'index'])->name('settings.custom-fields.index');
        Route::post('/settings/custom-fields', [CustomFieldController::class, 'store'])->name('settings.custom-fields.store');
        Route::put('/settings/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('settings.custom-fields.update');
        Route::delete('/settings/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('settings.custom-fields.destroy');
        Route::post('/settings/custom-fields/bulk-delete', [CustomFieldController::class, 'bulkDestroy'])->name('settings.custom-fields.bulk-destroy');
        Route::post('/settings/custom-fields/bulk-export', [CustomFieldController::class, 'bulkExport'])->name('settings.custom-fields.bulk-export');

        // Настройки: Роли и Права (Меню, Поля, Доступ к данным)
        Route::get('/settings/roles-permissions', [RolePermissionController::class, 'index'])->name('settings.roles-permissions.index');
        Route::post('/settings/roles-permissions/fields', [RolePermissionController::class, 'storeFields'])->name('settings.roles-permissions.fields.store');
        Route::post('/settings/roles-permissions/modules', [RolePermissionController::class, 'storeModules'])->name('settings.roles-permissions.modules.store');
        Route::post('/settings/roles-permissions/scopes', [RolePermissionController::class, 'storeScopes'])->name('settings.roles-permissions.scopes.store');

        // Настройки: Справочники (Марки и Модели)
        Route::get('/settings/dictionaries', [DictionaryController::class, 'index'])->name('settings.dictionaries.index');
        Route::post('/settings/dictionaries/makes', [DictionaryController::class, 'storeMake'])->name('settings.dictionaries.makes.store');
        Route::put('/settings/dictionaries/makes/{make}', [DictionaryController::class, 'updateMake'])->name('settings.dictionaries.makes.update');
        Route::delete('/settings/dictionaries/makes/{make}', [DictionaryController::class, 'destroyMake'])->name('settings.dictionaries.makes.destroy');
        Route::post('/settings/dictionaries/models', [DictionaryController::class, 'storeModel'])->name('settings.dictionaries.models.store');
        Route::put('/settings/dictionaries/models/{model}', [DictionaryController::class, 'updateModel'])->name('settings.dictionaries.models.update');
        Route::delete('/settings/dictionaries/models/{model}', [DictionaryController::class, 'destroyModel'])->name('settings.dictionaries.models.destroy');
        Route::post('/settings/dictionaries/import', [DictionaryController::class, 'importCsv'])->name('settings.dictionaries.import');

        // Настройки: Категории услуг и товаров
        Route::post('/settings/dictionaries/service-categories', [DictionaryController::class, 'storeServiceCategory'])->name('settings.dictionaries.service-categories.store');
        Route::put('/settings/dictionaries/service-categories/{category}', [DictionaryController::class, 'updateServiceCategory'])->name('settings.dictionaries.service-categories.update');
        Route::delete('/settings/dictionaries/service-categories/{category}', [DictionaryController::class, 'destroyServiceCategory'])->name('settings.dictionaries.service-categories.destroy');
        Route::post('/settings/dictionaries/product-categories', [DictionaryController::class, 'storeProductCategory'])->name('settings.dictionaries.product-categories.store');
        Route::put('/settings/dictionaries/product-categories/{category}', [DictionaryController::class, 'updateProductCategory'])->name('settings.dictionaries.product-categories.update');
        Route::delete('/settings/dictionaries/product-categories/{category}', [DictionaryController::class, 'destroyProductCategory'])->name('settings.dictionaries.product-categories.destroy');

        // Настройки: Универсальные справочники (Lookups)
        Route::post('/settings/lookups', [LookupController::class, 'store'])->name('settings.lookups.store');
        Route::put('/settings/lookups/{lookup}', [LookupController::class, 'update'])->name('settings.lookups.update');
        Route::delete('/settings/lookups/{lookup}', [LookupController::class, 'destroy'])->name('settings.lookups.destroy');
        Route::post('/settings/lookups/reorder', [LookupController::class, 'reorder'])->name('settings.lookups.reorder');

        // Настройки: CRM (Строгая валидация и т.д.)
        Route::get('/settings/crm', [CrmSettingsController::class, 'index'])->name('settings.crm.index');
        Route::post('/settings/crm', [CrmSettingsController::class, 'store'])->name('settings.crm.store');

        Route::get('/settings/loyalty', [LoyaltySettingsController::class, 'index'])->name('settings.loyalty.index');
        Route::post('/settings/loyalty', [LoyaltySettingsController::class, 'store'])->name('settings.loyalty.store');

        // Настройки: Зарплата (Фаза 10.1)
        Route::get('/settings/payroll', [PayrollSettingsController::class, 'index'])->name('settings.payroll.index');
        Route::post('/settings/payroll/general', [PayrollSettingsController::class, 'storeGeneral'])->name('settings.payroll.general.store');
        Route::post('/settings/payroll/rules', [PayrollSettingsController::class, 'storeRule'])->name('settings.payroll.rules.store');
        Route::put('/settings/payroll/rules/{rule}', [PayrollSettingsController::class, 'updateRule'])->name('settings.payroll.rules.update');
        Route::delete('/settings/payroll/rules/{rule}', [PayrollSettingsController::class, 'destroyRule'])->name('settings.payroll.rules.destroy');

        // Настройки: Каналы связи (Фаза 11.2)
        Route::get('/settings/channels', [ChannelController::class, 'index'])->name('settings.channels.index');
        Route::post('/settings/channels', [ChannelController::class, 'store'])->name('settings.channels.store');
        Route::put('/settings/channels/{channel}', [ChannelController::class, 'update'])->name('settings.channels.update');
        Route::delete('/settings/channels/{channel}', [ChannelController::class, 'destroy'])->name('settings.channels.destroy');
        Route::get('/settings/channels/{channel}/qr', [ChannelController::class, 'qrCode'])->name('settings.channels.qr');
        Route::get('/settings/channels/{channel}/status', [ChannelController::class, 'status'])->name('settings.channels.status');
        Route::post('/settings/channels/{channel}/retry-provision', [ChannelController::class, 'retryProvision'])->name('settings.channels.retry-provision');

        // Настройки: Шаблоны сообщений (Фаза 11.1)
        Route::get('/settings/message-templates', [MessageTemplateController::class, 'index'])->name('settings.message-templates.index');
        Route::post('/settings/message-templates', [MessageTemplateController::class, 'store'])->name('settings.message-templates.store');
        Route::put('/settings/message-templates/{messageTemplate}', [MessageTemplateController::class, 'update'])->name('settings.message-templates.update');
        Route::delete('/settings/message-templates/{messageTemplate}', [MessageTemplateController::class, 'destroy'])->name('settings.message-templates.destroy');

        // Общение: единый инбокс внешних чатов (Фаза 11.3)
        Route::get('/communications', [CommunicationsController::class, 'index'])->name('communications.index');
        Route::get('/communications/chats', [CommunicationsController::class, 'chats'])->name('communications.chats');

        // Настройки: Шаблоны документов (Фаза 12)
        Route::get('/settings/document-templates', [DocumentTemplateController::class, 'index'])->name('settings.document-templates.index');
        Route::post('/settings/document-templates', [DocumentTemplateController::class, 'store'])->name('settings.document-templates.store');
        Route::put('/settings/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update'])->name('settings.document-templates.update');
        Route::delete('/settings/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'destroy'])->name('settings.document-templates.destroy');
        Route::get('/settings/document-templates/library', [DocumentTemplateController::class, 'library'])->name('settings.document-templates.library');
        Route::get('/settings/document-templates/library/{id}/preview', [DocumentTemplateController::class, 'previewLibraryTemplate'])->name('settings.document-templates.library.preview');
        Route::post('/settings/document-templates/import', [DocumentTemplateController::class, 'import'])->name('settings.document-templates.import');

        // Документы: реестр + генерация печатных форм (Фаза 12)
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents/generate', [DocumentController::class, 'generate'])->name('documents.generate');
        Route::get('/documents/templates-for/{entityType}', [DocumentController::class, 'templatesFor'])->name('documents.templates-for');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::get('/documents/{document}/print', [DocumentController::class, 'print'])->name('documents.print');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        Route::post('/documents/{document}/regenerate-as-new', [DocumentController::class, 'regenerateAsNew'])->name('documents.regenerate-as-new');
        Route::post('/documents/{document}/replace', [DocumentController::class, 'replace'])->name('documents.replace');

        // HR: Должности
        Route::get('/hr/positions', [PositionController::class, 'index'])->name('hr.positions.index');
        Route::post('/hr/positions', [PositionController::class, 'store'])->name('hr.positions.store');
        Route::put('/hr/positions/{position}', [PositionController::class, 'update'])->name('hr.positions.update');
        Route::delete('/hr/positions/{position}', [PositionController::class, 'destroy'])->name('hr.positions.destroy');
        Route::post('/hr/positions/bulk-delete', [PositionController::class, 'bulkDestroy'])->name('hr.positions.bulk-destroy');
        Route::post('/hr/positions/bulk-export', [PositionController::class, 'bulkExport'])->name('hr.positions.bulk-export');

        // HR: Сотрудники
        Route::get('/hr/employees', [EmployeeController::class, 'index'])->name('hr.employees.index');
        Route::post('/hr/employees', [EmployeeController::class, 'store'])->name('hr.employees.store');
        Route::get('/hr/employees/{employee}', [EmployeeController::class, 'show'])->name('hr.employees.show');
        Route::put('/hr/employees/{employee}', [EmployeeController::class, 'update'])->name('hr.employees.update');
        Route::delete('/hr/employees/{employee}', [EmployeeController::class, 'destroy'])->name('hr.employees.destroy');
        Route::post('/hr/employees/{employee}/comment', [EmployeeController::class, 'addComment'])->name('hr.employees.comment');
        Route::post('/hr/employees/bulk-delete', [EmployeeController::class, 'bulkDestroy'])->name('hr.employees.bulk-destroy');
        Route::post('/hr/employees/bulk-export', [EmployeeController::class, 'bulkExport'])->name('hr.employees.bulk-export');

        // HR: Начисления/выплаты ЗП (Фаза 10.3)
        Route::get('/hr/payroll', [PayrollController::class, 'index'])->name('hr.payroll.index');
        Route::get('/hr/payroll/contractors/{client}/payroll', [PayrollController::class, 'contractorPayroll'])->name('hr.payroll.contractor');
        Route::post('/hr/payroll', [PayrollController::class, 'store'])->name('hr.payroll.store');
        Route::post('/hr/payroll/bulk-payout', [PayrollController::class, 'bulkPayout'])->name('hr.payroll.bulk-payout');
        Route::post('/hr/payroll/{payroll}/payout', [PayrollController::class, 'payout'])->name('hr.payroll.payout');
        Route::post('/hr/payroll/{payroll}/reverse-payout', [PayrollController::class, 'reversePayout'])->name('hr.payroll.reverse-payout');
        Route::post('/hr/payroll/reverse-bulk-payout', [PayrollController::class, 'reverseBulkPayout'])->name('hr.payroll.reverse-bulk-payout');
        Route::delete('/hr/payroll/{payroll}', [PayrollController::class, 'cancel'])->name('hr.payroll.cancel');

        // CRM: Клиенты
        Route::get('/crm/clients', [ClientController::class, 'index'])->name('crm.clients.index');
        Route::post('/crm/clients', [ClientController::class, 'store'])->name('crm.clients.store');
        Route::post('/crm/client-groups', [ClientController::class, 'storeGroup'])->name('crm.client-groups.store');
        Route::put('/crm/client-groups/{clientGroup}', [ClientController::class, 'updateGroup'])->name('crm.client-groups.update');
        Route::delete('/crm/client-groups/{clientGroup}', [ClientController::class, 'destroyGroup'])->name('crm.client-groups.destroy');
        Route::get('/crm/clients/{client}', [ClientController::class, 'show'])->name('crm.clients.show');
        Route::put('/crm/clients/{client}', [ClientController::class, 'update'])->name('crm.clients.update');
        Route::post('/crm/clients/{client}/group/auto', [ClientController::class, 'resetGroupToAuto'])->name('crm.clients.group.auto');
        Route::delete('/crm/clients/{client}', [ClientController::class, 'destroy'])->name('crm.clients.destroy');
        Route::post('/crm/clients/{client}/comment', [ClientController::class, 'addComment'])->name('crm.clients.comment');
        Route::get('/crm/clients/{client}/chats', [ChatController::class, 'forClient'])->name('crm.clients.chats');
        Route::post('/crm/clients/{client}/chats/send', [ChatController::class, 'send'])->name('crm.clients.chats.send');
        Route::post('/crm/clients/bulk-delete', [ClientController::class, 'bulkDestroy'])->name('crm.clients.bulk-destroy');
        Route::post('/crm/clients/bulk-export', [ClientController::class, 'bulkExport'])->name('crm.clients.bulk-export');

        // CRM: Автомобили
        Route::get('/crm/vehicles', [VehicleController::class, 'index'])->name('crm.vehicles.index');
        Route::post('/crm/vehicles', [VehicleController::class, 'store'])->name('crm.vehicles.store');
        Route::get('/crm/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('crm.vehicles.show');
        Route::put('/crm/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('crm.vehicles.update');
        Route::delete('/crm/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('crm.vehicles.destroy');
        Route::post('/crm/vehicles/{vehicle}/comment', [VehicleController::class, 'addComment'])->name('crm.vehicles.comment');
        Route::post('/crm/vehicles/bulk-delete', [VehicleController::class, 'bulkDestroy'])->name('crm.vehicles.bulk-destroy');
        Route::post('/crm/vehicles/bulk-export', [VehicleController::class, 'bulkExport'])->name('crm.vehicles.bulk-export');

        // Sales: Воронка сделок и канбан-доска (Фаза 17)
        Route::get('/sales/deals', [DealController::class, 'index'])->name('sales.deals.index');
        Route::post('/sales/deals', [DealController::class, 'store'])->name('sales.deals.store');
        Route::get('/sales/deals/{deal}', [DealController::class, 'show'])->name('sales.deals.show');
        Route::put('/sales/deals/{deal}', [DealController::class, 'update'])->name('sales.deals.update');
        Route::delete('/sales/deals/{deal}', [DealController::class, 'destroy'])->name('sales.deals.destroy');
        // Перенос между стадиями — сюда же ходит и drag-n-drop на доске, и select на Карточке
        Route::patch('/sales/deals/{deal}/stage', [DealController::class, 'moveStage'])->name('sales.deals.stage.update');
        Route::post('/sales/deals/{deal}/convert', [DealController::class, 'convertToWorkOrder'])->name('sales.deals.convert');
        Route::post('/sales/deals/{deal}/comment', [DealController::class, 'addComment'])->name('sales.deals.comment');
        // Догрузка следующей страницы карточек ОДНОЙ колонки (доска не грузит все сделки разом)
        Route::get('/sales/stages/{stage}/deals', [DealController::class, 'loadStage'])->name('sales.stages.deals');

        // Sales: Задачи (Фаза 17, этап 2) — общесистемные, поэтому не под /sales
        // логически, но маршрутами лежат рядом со сделками (основной драйвер этапа)
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

        // Settings: Воронки и стадии
        Route::get('/settings/pipelines', [PipelineSettingsController::class, 'index'])->name('settings.pipelines.index');
        Route::post('/settings/pipelines', [PipelineSettingsController::class, 'store'])->name('settings.pipelines.store');
        Route::put('/settings/pipelines/{pipeline}', [PipelineSettingsController::class, 'update'])->name('settings.pipelines.update');
        Route::delete('/settings/pipelines/{pipeline}', [PipelineSettingsController::class, 'destroy'])->name('settings.pipelines.destroy');
        Route::post('/settings/pipelines/{pipeline}/stages', [PipelineSettingsController::class, 'storeStage'])->name('settings.pipelines.stages.store');
        Route::put('/settings/pipeline-stages/{stage}', [PipelineSettingsController::class, 'updateStage'])->name('settings.pipelines.stages.update');
        Route::delete('/settings/pipeline-stages/{stage}', [PipelineSettingsController::class, 'destroyStage'])->name('settings.pipelines.stages.destroy');
        Route::post('/settings/pipelines/{pipeline}/stages/reorder', [PipelineSettingsController::class, 'reorderStages'])->name('settings.pipelines.stages.reorder');
        Route::post('/settings/pipeline-stages/{stage}/automations', [PipelineSettingsController::class, 'storeAutomation'])->name('settings.pipelines.stages.automations.store');
        Route::put('/settings/pipeline-stage-automations/{automation}', [PipelineSettingsController::class, 'updateAutomation'])->name('settings.pipelines.stages.automations.update');
        Route::delete('/settings/pipeline-stage-automations/{automation}', [PipelineSettingsController::class, 'destroyAutomation'])->name('settings.pipelines.stages.automations.destroy');

        // Operations: Заказ-наряды
        Route::get('/operations/work-orders', [WorkOrderController::class, 'index'])->name('operations.work-orders.index');
        Route::post('/operations/work-orders', [WorkOrderController::class, 'store'])->name('operations.work-orders.store');
        Route::get('/operations/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('operations.work-orders.show');
        Route::put('/operations/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('operations.work-orders.update');
        Route::delete('/operations/work-orders/{workOrder}', [WorkOrderController::class, 'destroy'])->name('operations.work-orders.destroy');
        Route::post('/operations/work-orders/bulk-delete', [WorkOrderController::class, 'bulkDestroy'])->name('operations.work-orders.bulk-destroy');
        Route::post('/operations/work-orders/bulk-export', [WorkOrderController::class, 'bulkExport'])->name('operations.work-orders.bulk-export');

        // Operations: Заказ-наряды (Позиции, Скидки, Финансы, Склад)
        Route::post('/operations/work-orders/{workOrder}/items', [WorkOrderController::class, 'addItem'])->name('operations.work-orders.items.store');
        Route::put('/operations/work-orders/{workOrder}/items/{item}', [WorkOrderController::class, 'updateItem'])->name('operations.work-orders.items.update');
        Route::delete('/operations/work-orders/{workOrder}/items/{item}', [WorkOrderController::class, 'removeItem'])->name('operations.work-orders.items.destroy');
        Route::post('/operations/work-orders/{workOrder}/items/auto-sort', [WorkOrderController::class, 'autoSortItems'])->name('operations.work-orders.items.auto-sort');
        Route::post('/operations/work-orders/{workOrder}/items/reorder', [WorkOrderController::class, 'reorderItems'])->name('operations.work-orders.items.reorder');
        Route::post('/operations/work-orders/{workOrder}/discount', [WorkOrderController::class, 'updateDiscount'])->name('operations.work-orders.discount.update');
        Route::post('/operations/work-orders/{workOrder}/payment', [WorkOrderController::class, 'processPayment'])->name('operations.work-orders.payment.store');
        Route::post('/operations/work-orders/{workOrder}/complete', [WorkOrderController::class, 'completeOrder'])->name('operations.work-orders.complete');
        Route::patch('/operations/work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus'])->name('operations.work-orders.status.update');
        Route::post('/operations/work-orders/{workOrder}/comment', [WorkOrderController::class, 'addComment'])->name('operations.work-orders.comment');
        Route::patch('/operations/work-orders/{workOrder}/admin', [WorkOrderController::class, 'updateAdmin'])->name('operations.work-orders.admin.update');
        Route::put('/operations/work-orders/{workOrder}/items/{item}/payout', [WorkOrderController::class, 'updateItemPayout'])->name('operations.work-orders.items.payout');
        Route::get('/operations/work-orders/{workOrder}/payroll-preview', [WorkOrderController::class, 'payrollPreview'])->name('operations.work-orders.payroll-preview');
        // Материалы на услугу (CLAUDE.md «Материалы на услугу»)
        Route::put('/operations/work-orders/{workOrder}/items/{item}/material-settings', [WorkOrderController::class, 'updateItemMaterialSettings'])->name('operations.work-orders.items.material-settings');
        Route::post('/operations/work-orders/{workOrder}/items/{item}/materials/auto-add', [WorkOrderController::class, 'autoAddMaterials'])->name('operations.work-orders.items.materials.auto-add');

        // Operations: Прайс-лист услуг (Перенесено из Warehouse в Operations)
        Route::get('/operations/services', [ServiceController::class, 'index'])->name('operations.services.index');
        Route::post('/operations/services', [ServiceController::class, 'store'])->name('operations.services.store');
        Route::put('/operations/services/{service}', [ServiceController::class, 'update'])->name('operations.services.update');
        Route::delete('/operations/services/{service}', [ServiceController::class, 'destroy'])->name('operations.services.destroy');
        Route::post('/operations/services/bulk-delete', [ServiceController::class, 'bulkDestroy'])->name('operations.services.bulk-destroy');
        Route::post('/operations/services/bulk-export', [ServiceController::class, 'bulkExport'])->name('operations.services.bulk-export');
        Route::post('/operations/service-categories', [ServiceController::class, 'storeCategory'])->name('operations.service-categories.store');
        // Материалы по умолчанию (CLAUDE.md «Материалы на услугу»)
        Route::post('/operations/services/{service}/materials', [ServiceController::class, 'storeDefaultMaterial'])->name('operations.services.materials.store');
        Route::put('/operations/service-materials/{material}', [ServiceController::class, 'updateDefaultMaterial'])->name('operations.services.materials.update');
        Route::delete('/operations/service-materials/{material}', [ServiceController::class, 'destroyDefaultMaterial'])->name('operations.services.materials.destroy');

        // Operations: Быстрое добавление номенклатуры (услуги теперь через items.store с is_custom/save_to_catalog)
        Route::post('/operations/work-orders/quick-product', [WorkOrderController::class, 'storeProductQuick'])->name('operations.work-orders.quick-product');

        // Operations: Записи (Фаза 9.2)
        Route::get('/operations/appointments', [AppointmentController::class, 'index'])->name('operations.appointments.index');
        Route::get('/operations/appointments/calendar-events', [AppointmentController::class, 'calendarEvents'])->name('operations.appointments.calendar-events');
        Route::post('/operations/appointments', [AppointmentController::class, 'store'])->name('operations.appointments.store');
        Route::put('/operations/appointments/{appointment}', [AppointmentController::class, 'update'])->name('operations.appointments.update');
        Route::delete('/operations/appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('operations.appointments.destroy');
        Route::post('/operations/appointments/bulk-delete', [AppointmentController::class, 'bulkDestroy'])->name('operations.appointments.bulk-destroy');
        Route::patch('/operations/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('operations.appointments.status.update');
        Route::post('/operations/appointments/{appointment}/convert', [AppointmentController::class, 'convertToWorkOrder'])->name('operations.appointments.convert');
        Route::post('/operations/appointments/{appointment}/link-work-order', [AppointmentController::class, 'linkWorkOrder'])->name('operations.appointments.link-work-order');

        // Warehouse: Товары и Материалы
        Route::get('/warehouse/products', [ProductController::class, 'index'])->name('warehouse.products.index');
        Route::post('/warehouse/products', [ProductController::class, 'store'])->name('warehouse.products.store');
        Route::put('/warehouse/products/{product}', [ProductController::class, 'update'])->name('warehouse.products.update');
        Route::delete('/warehouse/products/{product}', [ProductController::class, 'destroy'])->name('warehouse.products.destroy');
        Route::post('/warehouse/products/bulk-delete', [ProductController::class, 'bulkDestroy'])->name('warehouse.products.bulk-destroy');
        Route::post('/warehouse/products/bulk-export', [ProductController::class, 'bulkExport'])->name('warehouse.products.bulk-export');
        Route::post('/warehouse/product-categories', [ProductController::class, 'storeCategory'])->name('warehouse.product-categories.store');

        // Warehouse: Остатки, Движения, Приходные накладные — недоступны при
        // выключенном тумблере складского учёта (EnsureWarehouseEnabled).
        // warehouse.products.* сознательно ВНЕ этой группы — каталог и цены
        // товаров остаются доступны всегда.
        Route::middleware([EnsureWarehouseEnabled::class])->group(function () {
            Route::get('/warehouse/balances', [StockBalanceController::class, 'index'])->name('warehouse.balances.index');
            Route::post('/warehouse/balances/bulk-export', [StockBalanceController::class, 'bulkExport'])->name('warehouse.balances.bulk-export');

            Route::get('/warehouse/movements', [StockMovementController::class, 'index'])->name('warehouse.movements.index');
            Route::post('/warehouse/movements/bulk-export', [StockMovementController::class, 'bulkExport'])->name('warehouse.movements.bulk-export');

            // Warehouse: Приходные накладные (оприходование через поставщика — заменяет старое warehouse.movements.receipt)
            Route::get('/warehouse/suppliers-debt', [GoodsReceiptController::class, 'debts'])->name('warehouse.suppliers-debt.index');
            Route::get('/warehouse/goods-receipts', [GoodsReceiptController::class, 'index'])->name('warehouse.goods-receipts.index');
            Route::post('/warehouse/goods-receipts', [GoodsReceiptController::class, 'store'])->name('warehouse.goods-receipts.store');
            Route::get('/warehouse/goods-receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('warehouse.goods-receipts.show');
            Route::post('/warehouse/goods-receipts/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('warehouse.goods-receipts.cancel');
            Route::post('/warehouse/goods-receipts/{receipt}/pay', [GoodsReceiptController::class, 'pay'])->name('warehouse.goods-receipts.pay');
            Route::post('/warehouse/goods-receipts/{receipt}/items', [GoodsReceiptController::class, 'addItem'])->name('warehouse.goods-receipts.items.store');
            Route::put('/warehouse/goods-receipts/{receipt}/items/{item}', [GoodsReceiptController::class, 'updateItem'])->name('warehouse.goods-receipts.items.update');
            Route::delete('/warehouse/goods-receipts/{receipt}/items/{item}', [GoodsReceiptController::class, 'destroyItem'])->name('warehouse.goods-receipts.items.destroy');
        });

        // Finance: Статьи доходов и расходов
        Route::get('/finance/categories', [TransactionCategoryController::class, 'index'])->name('finance.categories.index');
        Route::post('/finance/categories', [TransactionCategoryController::class, 'store'])->name('finance.categories.store');
        Route::put('/finance/categories/{category}', [TransactionCategoryController::class, 'update'])->name('finance.categories.update');
        Route::delete('/finance/categories/{category}', [TransactionCategoryController::class, 'destroy'])->name('finance.categories.destroy');
        Route::post('/finance/categories/bulk-delete', [TransactionCategoryController::class, 'bulkDestroy'])->name('finance.categories.bulk-destroy');
        Route::post('/finance/categories/bulk-export', [TransactionCategoryController::class, 'bulkExport'])->name('finance.categories.bulk-export');

        // Finance: Транзакции
        Route::get('/finance/transactions', [TransactionController::class, 'index'])->name('finance.transactions.index');
        Route::post('/finance/transactions', [TransactionController::class, 'store'])->name('finance.transactions.store');
        Route::put('/finance/transactions/{transaction}', [TransactionController::class, 'update'])->name('finance.transactions.update');
        Route::delete('/finance/transactions/{transaction}', [TransactionController::class, 'destroy'])->name('finance.transactions.destroy');
        Route::post('/finance/transactions/bulk-export', [TransactionController::class, 'bulkExport'])->name('finance.transactions.bulk-export');
        Route::patch('/finance/transactions/{transaction}/reconcile', [TransactionController::class, 'toggleReconciled'])->name('finance.transactions.reconcile');

        // Finance: Дневные снэпшоты остатков
        Route::get('/finance/snapshots', [AccountSnapshotController::class, 'index'])->name('finance.snapshots.index');

        // Finance: Закрытие периода
        Route::get('/finance/period-closure', [PeriodClosureController::class, 'index'])->name('finance.period-closure.index');
        Route::post('/finance/period-closure', [PeriodClosureController::class, 'store'])->name('finance.period-closure.store');
    });

    require __DIR__.'/auth.php';
});

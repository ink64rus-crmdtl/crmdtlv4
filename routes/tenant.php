<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\PreventSandboxTenantHttpAccess;
use App\Http\Middleware\SetBranchContext;
use App\Http\Controllers\DumpController;
use App\Http\Controllers\Tenant\LegalEntityController;
use App\Http\Controllers\Tenant\AccountController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\SystemController;
use App\Http\Controllers\Tenant\BusinessDirectionController;
use App\Http\Controllers\Tenant\PostController;
use App\Http\Controllers\Tenant\WarehouseSettingsController;
use App\Http\Controllers\Tenant\CustomFieldController;
use App\Http\Controllers\Tenant\RolePermissionController;
use App\Http\Controllers\Tenant\PositionController;
use App\Http\Controllers\Tenant\EmployeeController;
use App\Http\Controllers\Tenant\ListViewController;
use App\Http\Controllers\Tenant\ClientController;
use App\Http\Controllers\Tenant\VehicleController;
use App\Http\Controllers\Tenant\DictionaryController;
use App\Http\Controllers\Tenant\CrmSettingsController;
use App\Http\Controllers\Tenant\NotificationController;
use App\Http\Controllers\Tenant\LookupController;
use App\Http\Controllers\Tenant\WorkOrderController;
use App\Http\Controllers\Tenant\AppointmentController;
use App\Http\Controllers\Tenant\ServiceController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\StockBalanceController;
use App\Http\Controllers\Tenant\StockMovementController;
use App\Http\Controllers\Tenant\TransactionCategoryController;
use App\Http\Controllers\Tenant\TransactionController;
use App\Http\Controllers\Tenant\AccountSnapshotController;
use App\Http\Controllers\Tenant\PeriodClosureController;
use Illuminate\Foundation\Application;
use Inertia\Inertia;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventSandboxTenantHttpAccess::class,
    PreventAccessFromCentralDomains::class,
    SetBranchContext::class,
])->group(function () {

    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });

    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', function () {
            return Inertia::render('Dashboard');
        })->name('dashboard');

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

        // Настройки: Счета
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
        Route::post('/hr/employees/bulk-delete', [EmployeeController::class, 'bulkDestroy'])->name('hr.employees.bulk-destroy');
        Route::post('/hr/employees/bulk-export', [EmployeeController::class, 'bulkExport'])->name('hr.employees.bulk-export');

        // CRM: Клиенты
        Route::get('/crm/clients', [ClientController::class, 'index'])->name('crm.clients.index');
        Route::post('/crm/clients', [ClientController::class, 'store'])->name('crm.clients.store');
        Route::post('/crm/client-groups', [ClientController::class, 'storeGroup'])->name('crm.client-groups.store');
        Route::get('/crm/clients/{client}', [ClientController::class, 'show'])->name('crm.clients.show');
        Route::put('/crm/clients/{client}', [ClientController::class, 'update'])->name('crm.clients.update');
        Route::delete('/crm/clients/{client}', [ClientController::class, 'destroy'])->name('crm.clients.destroy');
        Route::post('/crm/clients/bulk-delete', [ClientController::class, 'bulkDestroy'])->name('crm.clients.bulk-destroy');
        Route::post('/crm/clients/bulk-export', [ClientController::class, 'bulkExport'])->name('crm.clients.bulk-export');

        // CRM: Автомобили
        Route::get('/crm/vehicles', [VehicleController::class, 'index'])->name('crm.vehicles.index');
        Route::post('/crm/vehicles', [VehicleController::class, 'store'])->name('crm.vehicles.store');
        Route::get('/crm/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('crm.vehicles.show');
        Route::put('/crm/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('crm.vehicles.update');
        Route::delete('/crm/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('crm.vehicles.destroy');
        Route::post('/crm/vehicles/bulk-delete', [VehicleController::class, 'bulkDestroy'])->name('crm.vehicles.bulk-destroy');
        Route::post('/crm/vehicles/bulk-export', [VehicleController::class, 'bulkExport'])->name('crm.vehicles.bulk-export');

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
        
        // Operations: Прайс-лист услуг (Перенесено из Warehouse в Operations)
        Route::get('/operations/services', [ServiceController::class, 'index'])->name('operations.services.index');
        Route::post('/operations/services', [ServiceController::class, 'store'])->name('operations.services.store');
        Route::put('/operations/services/{service}', [ServiceController::class, 'update'])->name('operations.services.update');
        Route::delete('/operations/services/{service}', [ServiceController::class, 'destroy'])->name('operations.services.destroy');
        Route::post('/operations/services/bulk-delete', [ServiceController::class, 'bulkDestroy'])->name('operations.services.bulk-destroy');
        Route::post('/operations/services/bulk-export', [ServiceController::class, 'bulkExport'])->name('operations.services.bulk-export');
        Route::post('/operations/service-categories', [ServiceController::class, 'storeCategory'])->name('operations.service-categories.store');

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

        // Warehouse: Товары и Материалы
        Route::get('/warehouse/products', [ProductController::class, 'index'])->name('warehouse.products.index');
        Route::post('/warehouse/products', [ProductController::class, 'store'])->name('warehouse.products.store');
        Route::put('/warehouse/products/{product}', [ProductController::class, 'update'])->name('warehouse.products.update');
        Route::delete('/warehouse/products/{product}', [ProductController::class, 'destroy'])->name('warehouse.products.destroy');
        Route::post('/warehouse/products/bulk-delete', [ProductController::class, 'bulkDestroy'])->name('warehouse.products.bulk-destroy');
        Route::post('/warehouse/products/bulk-export', [ProductController::class, 'bulkExport'])->name('warehouse.products.bulk-export');
        Route::post('/warehouse/product-categories', [ProductController::class, 'storeCategory'])->name('warehouse.product-categories.store');

        // Warehouse: Остатки и Движения (Оприходование)
        Route::get('/warehouse/balances', [StockBalanceController::class, 'index'])->name('warehouse.balances.index');
        Route::post('/warehouse/balances/bulk-export', [StockBalanceController::class, 'bulkExport'])->name('warehouse.balances.bulk-export');
        
        Route::get('/warehouse/movements', [StockMovementController::class, 'index'])->name('warehouse.movements.index');
        Route::post('/warehouse/movements/receipt', [StockMovementController::class, 'storeReceipt'])->name('warehouse.movements.receipt');
        Route::post('/warehouse/movements/bulk-export', [StockMovementController::class, 'bulkExport'])->name('warehouse.movements.bulk-export');

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
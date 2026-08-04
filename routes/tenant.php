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
    });

    require __DIR__.'/auth.php';
});
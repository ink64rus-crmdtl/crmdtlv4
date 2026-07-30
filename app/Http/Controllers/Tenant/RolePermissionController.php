<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FieldPermission;
use App\Models\CustomFieldDefinition;
use App\Models\Module;
use App\Models\Role;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Models\BusinessDirection;
use App\Models\Warehouse;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RolePermissionController extends Controller
{
    public function index(): Response
    {
        // Получаем все роли, кроме admin, вместе с их текущими правами Spatie и Scopes
        $roles = Role::where('name', '!=', 'admin')
            ->with(['permissions', 'branches', 'legalEntities', 'businessDirections', 'warehouses', 'accounts'])
            ->get();

        // Получаем все модули
        $modules = Module::orderBy('sort_order')->get();

        // Получаем все справочники для Scopes
        $branches = Branch::where('is_active', true)->get(['id', 'name']);
        $legalEntities = LegalEntity::where('is_active', true)->get(['id', 'name']);
        $businessDirections = BusinessDirection::where('is_active', true)->get(['id', 'name']);
        $warehouses = Warehouse::where('is_active', true)->get(['id', 'name']);
        $accounts = Account::where('is_active', true)->get(['id', 'name']);

        // Базовые системные поля для каждой сущности
        $systemFields = [
            'client' => [
                ['key' => 'name', 'label' => 'Имя / Название', 'is_custom' => false],
                ['key' => 'phone', 'label' => 'Телефон', 'is_custom' => false],
                ['key' => 'email', 'label' => 'Email', 'is_custom' => false],
                ['key' => 'type', 'label' => 'Тип (b2c/b2b)', 'is_custom' => false],
                ['key' => 'discount_percent', 'label' => 'Скидка (%)', 'is_custom' => false],
                ['key' => 'is_lead', 'label' => 'Статус Лида', 'is_custom' => false],
            ],
            'vehicle' => [
                ['key' => 'make', 'label' => 'Марка', 'is_custom' => false],
                ['key' => 'model', 'label' => 'Модель', 'is_custom' => false],
                ['key' => 'plate_number', 'label' => 'Госномер', 'is_custom' => false],
                ['key' => 'vin', 'label' => 'VIN', 'is_custom' => false],
                ['key' => 'year', 'label' => 'Год выпуска', 'is_custom' => false],
            ],
            'work_order' => [
                ['key' => 'status', 'label' => 'Статус заказа', 'is_custom' => false],
                ['key' => 'payment_status', 'label' => 'Статус оплаты', 'is_custom' => false],
                ['key' => 'mileage', 'label' => 'Пробег', 'is_custom' => false],
                ['key' => 'total_amount', 'label' => 'Сумма (Итого)', 'is_custom' => false],
                ['key' => 'discount_amount', 'label' => 'Сумма скидки', 'is_custom' => false],
                ['key' => 'final_amount', 'label' => 'Сумма к оплате', 'is_custom' => false],
            ]
        ];

        // Подмешиваем кастомные поля
        $customFields = CustomFieldDefinition::all();
        foreach ($customFields as $cf) {
            if (isset($systemFields[$cf->entity_type])) {
                $systemFields[$cf->entity_type][] = [
                    'key' => $cf->key,
                    'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                    'is_custom' => true,
                ];
            }
        }

        $entities = [
            ['key' => 'client', 'label' => 'Клиенты', 'fields' => $systemFields['client']],
            ['key' => 'vehicle', 'label' => 'Автомобили', 'fields' => $systemFields['vehicle']],
            ['key' => 'work_order', 'label' => 'Заказ-наряды', 'fields' => $systemFields['work_order']],
        ];

        $existingFieldPermissions = FieldPermission::all();

        return Inertia::render('Settings/RolesPermissions/Index', [
            'roles' => $roles,
            'modules' => $modules,
            'entities' => $entities,
            'existingFieldPermissions' => $existingFieldPermissions,
            'scopes' => [
                'branches' => $branches,
                'legalEntities' => $legalEntities,
                'businessDirections' => $businessDirections,
                'warehouses' => $warehouses,
                'accounts' => $accounts,
            ]
        ]);
    }

    public function storeFields(Request $request)
    {
        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*.role_id' => ['required', 'exists:roles,id'],
            'permissions.*.entity_type' => ['required', 'string'],
            'permissions.*.field_key' => ['required', 'string'],
            'permissions.*.can_view' => ['required', 'boolean'],
            'permissions.*.can_edit' => ['required', 'boolean'],
        ]);

        // Очищаем старые права (так как мы сохраняем всю матрицу целиком)
        FieldPermission::truncate();

        // Массовая вставка новых прав
        if (!empty($validated['permissions'])) {
            $insertData = array_map(function ($perm) {
                return [
                    'role_id' => $perm['role_id'],
                    'entity_type' => $perm['entity_type'],
                    'field_key' => $perm['field_key'],
                    'can_view' => $perm['can_view'],
                    'can_edit' => $perm['can_edit'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $validated['permissions']);

            FieldPermission::insert($insertData);
        }

        // Очищаем кэш прав, чтобы FieldPermissionService сразу подхватил изменения
        Cache::flush();

        return redirect()->back()->with('success', 'Права доступа к полям успешно сохранены');
    }

    public function storeModules(Request $request)
    {
        $validated = $request->validate([
            'role_permissions' => ['array'],
            'role_permissions.*' => ['array'], // Массив имен прав (например, ['view_crm', 'view_finance'])
        ]);

        foreach ($validated['role_permissions'] ?? [] as $roleId => $permissions) {
            $role = Role::find($roleId);
            if ($role && $role->name !== 'admin') {
                $role->syncPermissions($permissions);
            }
        }

        return redirect()->back()->with('success', 'Права доступа к разделам успешно сохранены');
    }

    public function storeScopes(Request $request)
    {
        $validated = $request->validate([
            'role_scopes' => ['array'],
            'role_scopes.*.branches' => ['array'],
            'role_scopes.*.legal_entities' => ['array'],
            'role_scopes.*.business_directions' => ['array'],
            'role_scopes.*.warehouses' => ['array'],
            'role_scopes.*.accounts' => ['array'],
        ]);

        foreach ($validated['role_scopes'] ?? [] as $roleId => $scopes) {
            $role = Role::find($roleId);
            if ($role && $role->name !== 'admin') {
                $role->branches()->sync($scopes['branches'] ?? []);
                $role->legalEntities()->sync($scopes['legal_entities'] ?? []);
                $role->businessDirections()->sync($scopes['business_directions'] ?? []);
                $role->warehouses()->sync($scopes['warehouses'] ?? []);
                $role->accounts()->sync($scopes['accounts'] ?? []);
            }
        }

        return redirect()->back()->with('success', 'Права доступа к данным (Scopes) успешно сохранены');
    }
}
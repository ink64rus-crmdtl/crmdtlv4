<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Position;
use App\Models\LegalEntity;
use App\Models\BusinessDirection;
use App\Models\Warehouse;
use App\Models\Account;
use App\Models\CustomFieldDefinition;
use App\Models\ListView;
use App\Services\FieldPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        $employees = Employee::with(['user.roles', 'branch', 'position'])->orderBy('id', 'desc')->get();
        $branches = Branch::where('is_active', true)->get(['id', 'name']);
        $positions = Position::where('is_active', true)->get(['id', 'name']);
        $roles = Role::where('name', '!=', 'admin')->get(['id', 'name']);
        
        $scopes = [
            'branches' => $branches,
            'legalEntities' => LegalEntity::where('is_active', true)->get(['id', 'name']),
            'businessDirections' => BusinessDirection::where('is_active', true)->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name']),
            'accounts' => Account::where('is_active', true)->get(['id', 'name']),
        ];

        // Подгружаем индивидуальные доступы для пользователей, чтобы передать на фронт
        $userScopes = [];
        foreach ($employees as $employee) {
            if ($employee->user_id) {
                $u = $employee->user;
                $userScopes[$employee->user_id] = [
                    'branches' => $u->branches()->pluck('branches.id')->toArray(),
                    'legal_entities' => $u->legalEntities()->pluck('legal_entities.id')->toArray(),
                    'business_directions' => $u->businessDirections()->pluck('business_directions.id')->toArray(),
                    'warehouses' => $u->warehouses()->pluck('warehouses.id')->toArray(),
                    'accounts' => $u->accounts()->pluck('accounts.id')->toArray(),
                ];
            }
        }

        $tenantCountry = config('tenant.country_code', 'RU');

        // --- ДИНАМИЧЕСКИЕ ТАБЛИЦЫ И ПРАВА ДОСТУПА ---
        
        // 1. Формируем базовый список всех возможных колонок
        $baseColumns = [
            ['key' => 'employee_name', 'label' => 'Сотрудник', 'type' => 'system', 'is_default' => true],
            ['key' => 'position_type', 'label' => 'Должность / Тип', 'type' => 'system', 'is_default' => true],
            ['key' => 'branch', 'label' => 'Филиал', 'type' => 'system', 'is_default' => true],
            ['key' => 'crm_access', 'label' => 'Доступ в CRM', 'type' => 'system', 'is_default' => true],
            ['key' => 'phone', 'label' => 'Телефон', 'type' => 'system', 'is_default' => false],
            ['key' => 'personal_email', 'label' => 'Личный Email', 'type' => 'system', 'is_default' => false],
            ['key' => 'birth_date', 'label' => 'Дата рождения', 'type' => 'system', 'is_default' => false],
            ['key' => 'hire_date', 'label' => 'Дата приема', 'type' => 'system', 'is_default' => false],
            ['key' => 'termination_date', 'label' => 'Дата увольнения', 'type' => 'system', 'is_default' => false],
        ];

        // 2. Подмешиваем кастомные поля для Сотрудников
        $customFields = CustomFieldDefinition::where('entity_type', 'employee')->get();
        foreach ($customFields as $cf) {
            $baseColumns[] = [
                'key' => $cf->key,
                'label' => $cf->label[app()->getLocale()] ?? current($cf->label),
                'type' => 'custom',
                'is_default' => $cf->is_visible_in_list,
            ];
        }

        // 3. Фильтруем колонки через FieldPermissionService (отсекаем те, к которым нет прав)
        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'employee', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        // 4. Получаем сохраненный вид таблицы для текущего пользователя
        $listView = ListView::where('entity_type', 'employee')
            ->where('user_id', $user->id)
            ->first();

        // Если вида нет, берем дефолтные колонки из доступных
        $visibleColumns = $listView 
            ? $listView->visible_columns 
            : array_values(array_map(fn($c) => $c['key'], array_filter($availableColumns, fn($c) => $c['is_default'])));

        return Inertia::render('HR/Employees/Index', [
            'employees' => $employees,
            'branches' => $branches,
            'positions' => $positions,
            'roles' => $roles,
            'scopes' => $scopes,
            'userScopes' => $userScopes,
            'tenantCountry' => $tenantCountry,
            'availableColumns' => $availableColumns,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
        ]);
    }

    public function show(Employee $employee): Response
    {
        $employee->load(['user.roles', 'branch', 'position']);
        
        $resolvedScopes = [];
        if ($employee->user_id) {
            $user = $employee->user;
            $resolvedScopes = [
                'branches' => $user->branches()->get(['branches.id', 'branches.name']),
                'legal_entities' => $user->legalEntities()->get(['legal_entities.id', 'legal_entities.name']),
                'business_directions' => $user->businessDirections()->get(['business_directions.id', 'business_directions.name']),
                'warehouses' => $user->warehouses()->get(['warehouses.id', 'warehouses.name']),
                'accounts' => $user->accounts()->get(['accounts.id', 'accounts.name']),
            ];
        }

        return Inertia::render('HR/Employees/Show', [
            'employee' => $employee,
            'resolvedScopes' => $resolvedScopes,
        ]);
    }

    public function store(Request $request)
    {
        $tenantCountry = config('tenant.country_code', 'RU');
        $needsMiddleName = in_array($tenantCountry, ['RU', 'BY', 'KZ']);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => [$needsMiddleName ? 'required' : 'nullable', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:255',
                Rule::unique('employees')->where(function ($query) use ($request) {
                    return $query->where('branch_id', $request->branch_id)->whereNull('deleted_at');
                })
            ],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'type' => ['required', 'string', 'in:staff,self_employed,outsource'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'passport_data' => ['nullable', 'array'],
            
            // CRM Access
            'has_crm_access' => ['boolean'],
            'email' => ['exclude_if:has_crm_access,false', 'required', 'email', 'unique:users,email'],
            'password' => ['exclude_if:has_crm_access,false', 'required', Password::defaults()],
            'role_id' => ['exclude_if:has_crm_access,false', 'required', 'exists:roles,id'],

            // Scopes
            'scopes' => ['nullable', 'array'],
            'scopes.branches' => ['array'],
            'scopes.legal_entities' => ['array'],
            'scopes.business_directions' => ['array'],
            'scopes.warehouses' => ['array'],
            'scopes.accounts' => ['array'],
        ], [
            'phone.unique' => 'Сотрудник с таким номером телефона уже существует в выбранном филиале.',
        ]);

        DB::transaction(function () use ($validated) {
            $userId = null;

            if (!empty($validated['has_crm_access'])) {
                $user = User::create([
                    'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                ]);

                $role = Role::findById($validated['role_id']);
                $user->assignRole($role);

                if (!empty($validated['scopes'])) {
                    $user->branches()->sync($validated['scopes']['branches'] ?? []);
                    $user->legalEntities()->sync($validated['scopes']['legal_entities'] ?? []);
                    $user->businessDirections()->sync($validated['scopes']['business_directions'] ?? []);
                    $user->warehouses()->sync($validated['scopes']['warehouses'] ?? []);
                    $user->accounts()->sync($validated['scopes']['accounts'] ?? []);
                }

                $userId = $user->id;
            }

            Employee::create([
                'user_id' => $userId,
                'branch_id' => $validated['branch_id'],
                'position_id' => $validated['position_id'],
                'type' => $validated['type'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'phone' => $validated['phone'],
                'personal_email' => $validated['personal_email'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'hire_date' => $validated['hire_date'] ?? null,
                'termination_date' => $validated['termination_date'] ?? null,
                'passport_data' => $validated['passport_data'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        });

        return redirect()->back()->with('success', 'Сотрудник успешно добавлен');
    }

    public function update(Request $request, Employee $employee)
    {
        $tenantCountry = config('tenant.country_code', 'RU');
        $needsMiddleName = in_array($tenantCountry, ['RU', 'BY', 'KZ']);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => [$needsMiddleName ? 'required' : 'nullable', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:255',
                Rule::unique('employees')->ignore($employee->id)->where(function ($query) use ($request) {
                    return $query->where('branch_id', $request->branch_id)->whereNull('deleted_at');
                })
            ],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'type' => ['required', 'string', 'in:staff,self_employed,outsource'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'passport_data' => ['nullable', 'array'],
            
            // CRM Access
            'has_crm_access' => ['boolean'],
            'email' => ['exclude_if:has_crm_access,false', 'required', 'email', 'unique:users,email,' . $employee->user_id],
            'password' => ['nullable', Password::defaults()],
            'role_id' => ['exclude_if:has_crm_access,false', 'required', 'exists:roles,id'],

            // Scopes
            'scopes' => ['nullable', 'array'],
            'scopes.branches' => ['array'],
            'scopes.legal_entities' => ['array'],
            'scopes.business_directions' => ['array'],
            'scopes.warehouses' => ['array'],
            'scopes.accounts' => ['array'],
        ], [
            'phone.unique' => 'Сотрудник с таким номером телефона уже существует в выбранном филиале.',
        ]);

        DB::transaction(function () use ($validated, $employee) {
            $userId = $employee->user_id;

            if (!empty($validated['has_crm_access'])) {
                if ($userId) {
                    // Обновляем существующего пользователя
                    $user = User::find($userId);
                    $userData = [
                        'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                        'email' => $validated['email'],
                    ];
                    if (!empty($validated['password'])) {
                        $userData['password'] = Hash::make($validated['password']);
                    }
                    $user->update($userData);

                    $role = Role::findById($validated['role_id']);
                    $user->syncRoles([$role]);
                } else {
                    // Создаем нового пользователя
                    $user = User::create([
                        'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                        'email' => $validated['email'],
                        'password' => Hash::make($validated['password']),
                    ]);
                    $role = Role::findById($validated['role_id']);
                    $user->assignRole($role);
                    $userId = $user->id;
                }

                if (!empty($validated['scopes'])) {
                    $user->branches()->sync($validated['scopes']['branches'] ?? []);
                    $user->legalEntities()->sync($validated['scopes']['legal_entities'] ?? []);
                    $user->businessDirections()->sync($validated['scopes']['business_directions'] ?? []);
                    $user->warehouses()->sync($validated['scopes']['warehouses'] ?? []);
                    $user->accounts()->sync($validated['scopes']['accounts'] ?? []);
                }
            } else {
                // Отзываем доступ, если он был
                if ($userId) {
                    $user = User::find($userId);
                    if ($user) {
                        $user->branches()->detach();
                        $user->legalEntities()->detach();
                        $user->businessDirections()->detach();
                        $user->warehouses()->detach();
                        $user->accounts()->detach();
                        $user->delete();
                    }
                    $userId = null;
                }
            }

            $employee->update([
                'user_id' => $userId,
                'branch_id' => $validated['branch_id'],
                'position_id' => $validated['position_id'],
                'type' => $validated['type'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'phone' => $validated['phone'],
                'personal_email' => $validated['personal_email'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'hire_date' => $validated['hire_date'] ?? null,
                'termination_date' => $validated['termination_date'] ?? null,
                'passport_data' => $validated['passport_data'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        });

        return redirect()->back()->with('success', 'Данные сотрудника обновлены');
    }

    public function destroy(Employee $employee)
    {
        DB::transaction(function () use ($employee) {
            if ($employee->user_id) {
                $user = User::find($employee->user_id);
                if ($user) {
                    $user->branches()->detach();
                    $user->legalEntities()->detach();
                    $user->businessDirections()->detach();
                    $user->warehouses()->detach();
                    $user->accounts()->detach();
                    $user->delete();
                }
            }
            $employee->delete();
        });

        return redirect()->back()->with('success', 'Сотрудник удален');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated) {
            $employees = Employee::whereIn('id', $validated['ids'])->get();
            foreach ($employees as $employee) {
                if ($employee->user_id) {
                    $user = User::find($employee->user_id);
                    if ($user) {
                        $user->branches()->detach();
                        $user->legalEntities()->detach();
                        $user->businessDirections()->detach();
                        $user->warehouses()->detach();
                        $user->accounts()->detach();
                        $user->delete();
                    }
                }
                $employee->delete();
            }
        });

        return redirect()->back()->with('success', 'Выбранные сотрудники удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:employees,id'],
        ]);

        $employees = Employee::with(['branch', 'position', 'user.roles'])->whereIn('id', $validated['ids'])->get();
        
        $filename = 'employees_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];
        
        $callback = function() use($employees) {
            $file = fopen('php://output', 'w');
            // Добавляем BOM для корректного отображения UTF-8 в Excel
            fputs($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            fputcsv($file, ['ID', 'Фамилия', 'Имя', 'Отчество', 'Телефон', 'Email', 'Филиал', 'Должность', 'Тип', 'Статус'], ';');
            
            foreach ($employees as $emp) {
                $posName = 'Без должности';
                if ($emp->position) {
                    $posName = is_array($emp->position->name) ? ($emp->position->name['ru'] ?? current($emp->position->name)) : $emp->position->name;
                }

                $employeeTypes = [
                    'staff' => 'В штате',
                    'self_employed' => 'Самозанятый',
                    'outsource' => 'Аутсорс / Подрядчик'
                ];

                fputcsv($file, [
                    $emp->id,
                    $emp->last_name,
                    $emp->first_name,
                    $emp->middle_name,
                    $emp->phone,
                    $emp->personal_email,
                    $emp->branch ? $emp->branch->name : '',
                    $posName,
                    $employeeTypes[$emp->type] ?? $emp->type,
                    $emp->is_active ? 'Активен' : 'Уволен'
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
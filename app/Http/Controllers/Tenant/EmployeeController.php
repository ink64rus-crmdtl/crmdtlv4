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
use App\Models\CustomFieldValue;
use App\Models\ListView;
use App\Models\PayrollRule;
use App\Models\Payroll;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\FieldPermissionService;
use App\Services\QueryFilterService;
use App\Services\ActivityLogger;
use App\Jobs\ExportEntitiesJob;
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
        
        $query = Employee::with(['user.roles', 'branch' => fn ($q) => $q->withTrashed(), 'position']);
        
        // Применяем серверную фильтрацию и поиск
        $query = QueryFilterService::apply(
            $query, 
            request()->all(), 
            ['first_name', 'last_name', 'phone', 'personal_email'], 
            'employee'
        );

        // Сортировка по умолчанию
        if (!request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        // Пагинация вместо ->get()
        $employees = $query->paginate(15)->withQueryString();
        
        $branches = Branch::forSelect()->get(['id', 'name']);
        $positions = Position::where('is_active', true)->get(['id', 'name']);
        $roles = Role::where('name', '!=', 'admin')->get(['id', 'name']);
        
        $scopes = [
            'branches' => $branches,
            'legalEntities' => LegalEntity::where('is_active', true)->get(['id', 'name']),
            'businessDirections' => BusinessDirection::where('is_active', true)->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name']),
            'accounts' => Account::where('is_active', true)->get(['id', 'name']),
        ];

        // Подгружаем индивидуальные доступы для пользователей
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
            ['key' => 'branch', 'label' => 'Локация', 'type' => 'system', 'is_default' => true],
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

        // 3. Загружаем значения кастомных полей
        $cfValues = CustomFieldValue::where('entity_type', 'employee')
            ->whereIn('entity_id', $employees->getCollection()->pluck('id'))
            ->get();

        // 4. Мапим значения кастомных полей внутрь объектов
        $employees->getCollection()->transform(function ($employee) use ($cfValues, $customFields) {
            $employeeData = $employee->toArray();
            $employeeData['custom_fields'] = [];
            
            foreach ($customFields as $def) {
                $val = $cfValues->where('entity_id', $employee->id)->where('custom_field_definition_id', $def->id)->first();
                $employeeData['custom_fields'][$def->key] = $val ? ($val->value_text ?? $val->value_number ?? $val->value_date ?? $val->value) : null;
            }
            
            return $employeeData;
        });

        // 5. Фильтруем колонки через FieldPermissionService
        $allFieldKeys = array_column($baseColumns, 'key');
        $visibleKeys = FieldPermissionService::visibleFields($user, 'employee', $allFieldKeys);

        $availableColumns = array_values(array_filter($baseColumns, function($col) use ($visibleKeys) {
            return in_array($col['key'], $visibleKeys);
        }));

        // 6. Получаем сохраненный вид таблицы для текущего пользователя
        $listView = ListView::where('entity_type', 'employee')
            ->where('user_id', $user->id)
            ->first();

        $visibleColumns = $listView 
            ? $listView->visible_columns 
            : array_values(array_map(fn($c) => $c['key'], array_filter($availableColumns, fn($c) => $c['is_default'])));

        return Inertia::render('HR/Employees/Index', [
            'employees' => $employees,
            'filters' => request()->all(),
            'branches' => $branches,
            'positions' => $positions,
            'roles' => $roles,
            'scopes' => $scopes,
            'userScopes' => $userScopes,
            'tenantCountry' => $tenantCountry,
            'availableColumns' => $availableColumns,
            'customFieldDefs' => $customFields,
            'listView' => [
                'visible_columns' => $visibleColumns,
            ],
        ]);
    }

    public function show(Employee $employee): Response
    {
        $employee->load(['user.roles', 'branch' => fn ($q) => $q->withTrashed(), 'position', 'secondaryPosition']);
        
        $resolvedScopes = [];
        $userScopes = [];
        
        if ($employee->user_id) {
            $user = $employee->user;
            $resolvedScopes = [
                'branches' => $user->branches()->get(['branches.id', 'branches.name']),
                'legal_entities' => $user->legalEntities()->get(['legal_entities.id', 'legal_entities.name']),
                'business_directions' => $user->businessDirections()->get(['business_directions.id', 'business_directions.name']),
                'warehouses' => $user->warehouses()->get(['warehouses.id', 'warehouses.name']),
                'accounts' => $user->accounts()->get(['accounts.id', 'accounts.name']),
            ];
            
            $userScopes[$employee->user_id] = [
                'branches' => $user->branches()->pluck('branches.id')->toArray(),
                'legal_entities' => $user->legalEntities()->pluck('legal_entities.id')->toArray(),
                'business_directions' => $user->businessDirections()->pluck('business_directions.id')->toArray(),
                'warehouses' => $user->warehouses()->pluck('warehouses.id')->toArray(),
                'accounts' => $user->accounts()->pluck('accounts.id')->toArray(),
            ];
        }

        // Данные для модального окна редактирования
        $branches = Branch::forSelect()->get(['id', 'name']);
        $positions = Position::where('is_active', true)->get(['id', 'name']);
        $roles = Role::where('name', '!=', 'admin')->get(['id', 'name']);
        
        $scopes = [
            'branches' => $branches,
            'legalEntities' => LegalEntity::where('is_active', true)->get(['id', 'name']),
            'businessDirections' => BusinessDirection::where('is_active', true)->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name']),
            'accounts' => Account::where('is_active', true)->get(['id', 'name']),
        ];

        $tenantCountry = config('tenant.country_code', 'RU');

        // "История"/"Комментарии" — без roll-up (пока нет событий, ссылающихся
        // на employee_id; появится в Фазе 10 вместе с начислениями зарплаты).
        ['activities' => $activities, 'comments' => $comments] = ActivityLogger::present(ActivityLogger::feedFor($employee));

        // Фаза 10.1: персональные ставки сотрудника — самый приоритетный
        // уровень каскада (выше общей ставки должности из Settings → Зарплата).
        $personalPayrollRules = PayrollRule::where('employee_id', $employee->id)
            ->with(['service', 'serviceCategory', 'branch'])
            ->orderBy('id', 'desc')
            ->get();

        // Фаза 10.3: начисления/штрафы/оклад и их выплата.
        $payrollEntries = Payroll::where('employee_id', $employee->id)
            ->with('transaction:id,transaction_date')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        $payoutAccounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type']);

        // Фаза 10.4: баланс взаиморасчётов — считаем по ВСЕЙ истории начислений
        // (не по обрезанным до 50 строк $payrollEntries), та же формула, что и в
        // общем отчёте PayrollController::index().
        $balanceRow = Payroll::where('employee_id', $employee->id)
            ->selectRaw("
                SUM(CASE WHEN type = 'accrual' AND status != 'canceled' THEN amount ELSE 0 END) as accrued_total,
                SUM(CASE WHEN type = 'accrual' AND status = 'paid' THEN amount ELSE 0 END) as paid_total,
                SUM(CASE WHEN type = 'deduction' AND status = 'pending' THEN amount ELSE 0 END) as deductions_total
            ")
            ->first();

        $accruedTotal = (int) ($balanceRow->accrued_total ?? 0);
        $paidTotal = (int) ($balanceRow->paid_total ?? 0);
        $deductionsTotal = (int) ($balanceRow->deductions_total ?? 0);

        $payrollBalance = [
            'accrued_total' => $accruedTotal,
            'paid_total' => $paidTotal,
            'deductions_total' => $deductionsTotal,
            'balance' => $accruedTotal - $paidTotal - $deductionsTotal,
        ];

        return Inertia::render('HR/Employees/Show', [
            'employee' => $employee,
            'resolvedScopes' => $resolvedScopes,
            'branches' => $branches,
            'positions' => $positions,
            'roles' => $roles,
            'scopes' => $scopes,
            'userScopes' => $userScopes,
            'tenantCountry' => $tenantCountry,
            'activities' => $activities,
            'comments' => $comments,
            'personalPayrollRules' => $personalPayrollRules,
            'serviceCategories' => ServiceCategory::where('is_active', true)->get(['id', 'name']),
            'services' => Service::where('is_active', true)->get(['id', 'name', 'service_category_id']),
            'payrollEntries' => $payrollEntries,
            'payoutAccounts' => $payoutAccounts,
            'payrollBalance' => $payrollBalance,
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
            'secondary_position_id' => ['nullable', 'different:position_id', 'exists:positions,id'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'self_employed_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'type' => ['required', 'string', 'in:staff,self_employed,outsource'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'calendar_color' => ['nullable', 'string', Rule::in(\App\Support\CalendarPalette::COLORS)],
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
            'phone.unique' => 'Сотрудник с таким номером телефона уже существует в выбранной локации.',
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

            $employee = Employee::create([
                'user_id' => $userId,
                'branch_id' => $validated['branch_id'],
                'position_id' => $validated['position_id'],
                'secondary_position_id' => $validated['secondary_position_id'] ?? null,
                'salary_amount' => isset($validated['salary_amount']) ? (int) round($validated['salary_amount'] * 100) : null,
                'self_employed_tax_percent' => $validated['self_employed_tax_percent'] ?? null,
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
                'calendar_color' => $validated['calendar_color'] ?? null,
            ]);

            ActivityLogger::log($employee, 'Сотрудник добавлен', [], 'created');
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
            'secondary_position_id' => ['nullable', 'different:position_id', 'exists:positions,id'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'self_employed_tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'type' => ['required', 'string', 'in:staff,self_employed,outsource'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'calendar_color' => ['nullable', 'string', Rule::in(\App\Support\CalendarPalette::COLORS)],
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
            'phone.unique' => 'Сотрудник с таким номером телефона уже существует в выбранной локации.',
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
                'secondary_position_id' => $validated['secondary_position_id'] ?? null,
                'salary_amount' => isset($validated['salary_amount']) ? (int) round($validated['salary_amount'] * 100) : null,
                'self_employed_tax_percent' => $validated['self_employed_tax_percent'] ?? null,
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
                'calendar_color' => $validated['calendar_color'] ?? null,
            ]);

            ActivityLogger::log($employee, 'Данные сотрудника обновлены', [], 'updated');
        });

        return redirect()->back()->with('success', 'Данные сотрудника обновлены');
    }

    public function addComment(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        ActivityLogger::log($employee, $validated['comment'], [], 'comment');

        return redirect()->back()->with('success', 'Комментарий добавлен');
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

        ExportEntitiesJob::dispatch('employees', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }
}
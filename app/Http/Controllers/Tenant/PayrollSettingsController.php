<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollSettingsController extends Controller
{
    public function index(): Response
    {
        $generalSettings = [
            'apply_discount_to_base' => Setting::where('key', 'payroll_apply_discount_to_base')->value('value') !== '0',
            'worker_base_excludes_materials' => Setting::where('key', 'payroll_worker_base_excludes_materials')->value('value') !== '0',
            'worker_base_excludes_admin_share' => Setting::where('key', 'payroll_worker_base_excludes_admin_share')->value('value') === '1',
            'default_self_employed_tax_percent' => (float) (Setting::where('key', 'payroll_default_self_employed_tax_percent')->value('value') ?? 6),
            'salary_accrual_day' => (int) (Setting::where('key', 'payroll_salary_accrual_day')->value('value') ?? 1),
        ];

        $rules = PayrollRule::with(['position', 'employee', 'service', 'serviceCategory', 'branch'])
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('Settings/Payroll/Index', [
            'generalSettings' => $generalSettings,
            'positionRules' => $rules->whereNull('employee_id')->values(),
            'personalRulesCount' => $rules->whereNotNull('employee_id')->count(),
            'positions' => Position::where('is_active', true)->get(['id', 'name', 'payroll_role']),
            'serviceCategories' => ServiceCategory::where('is_active', true)->get(['id', 'name']),
            'services' => Service::where('is_active', true)->get(['id', 'name', 'service_category_id']),
            'branches' => Branch::forSelect()->get(['id', 'name']),
        ]);
    }

    public function storeGeneral(Request $request)
    {
        $validated = $request->validate([
            'apply_discount_to_base' => ['required', 'boolean'],
            'worker_base_excludes_materials' => ['required', 'boolean'],
            'worker_base_excludes_admin_share' => ['required', 'boolean'],
            'default_self_employed_tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'salary_accrual_day' => ['required', 'integer', 'min:1', 'max:28'],
        ]);

        Setting::updateOrCreate(['key' => 'payroll_apply_discount_to_base'], ['value' => $validated['apply_discount_to_base'] ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'payroll_worker_base_excludes_materials'], ['value' => $validated['worker_base_excludes_materials'] ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'payroll_worker_base_excludes_admin_share'], ['value' => $validated['worker_base_excludes_admin_share'] ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'payroll_default_self_employed_tax_percent'], ['value' => (string) $validated['default_self_employed_tax_percent']]);
        Setting::updateOrCreate(['key' => 'payroll_salary_accrual_day'], ['value' => (string) $validated['salary_accrual_day']]);

        return redirect()->back()->with('success', 'Общие настройки зарплаты сохранены');
    }

    /**
     * Одна и та же точка входа используется и для общих ставок (Settings →
     * Зарплата, position_id) и для персональных ставок сотрудника (карточка
     * сотрудника, вкладка Зарплата, employee_id) — правило одно и то же,
     * различается только тем, к чему оно привязано.
     */
    public function storeRule(Request $request)
    {
        $validated = $this->validateRule($request);
        $this->assertNoDuplicate($validated);

        PayrollRule::create($validated);

        return redirect()->back()->with('success', 'Ставка добавлена');
    }

    public function updateRule(Request $request, PayrollRule $rule)
    {
        $validated = $this->validateRule($request);
        $this->assertNoDuplicate($validated, $rule->id);

        $rule->update($validated);

        return redirect()->back()->with('success', 'Ставка обновлена');
    }

    public function destroyRule(PayrollRule $rule)
    {
        $rule->delete();

        return redirect()->back()->with('success', 'Ставка удалена');
    }

    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'position_id' => ['nullable', 'required_without:employee_id', 'exists:positions,id'],
            'employee_id' => ['nullable', 'required_without:position_id', 'exists:employees,id'],
            'target' => ['required', 'string', 'in:service,category,default'],
            'service_id' => ['nullable', 'required_if:target,service', 'exists:services,id'],
            'service_category_id' => ['nullable', 'required_if:target,category', 'exists:service_categories,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'fixed_amount' => ['nullable', 'required_if:type,fixed', 'numeric', 'min:0'],
            'percentage_value' => ['nullable', 'required_if:type,percentage', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validated['position_id'] ?? null) {
            $position = Position::findOrFail($validated['position_id']);
            if ($position->payroll_role === 'admin' && $validated['type'] === 'fixed') {
                throw ValidationException::withMessages([
                    'type' => 'Для должности с ролью "Администратор" нельзя назначить фиксированную ставку — только процент.',
                ]);
            }
        }

        return [
            'position_id' => $validated['position_id'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'service_id' => $validated['target'] === 'service' ? $validated['service_id'] : null,
            'service_category_id' => $validated['target'] === 'category' ? $validated['service_category_id'] : null,
            'is_default_for_unlisted' => $validated['target'] === 'default',
            'branch_id' => $validated['branch_id'] ?? null,
            'type' => $validated['type'],
            'fixed_amount' => $validated['type'] === 'fixed' ? (int) round($validated['fixed_amount'] * 100) : 0,
            'percentage_value' => $validated['type'] === 'percentage' ? $validated['percentage_value'] : 0,
            'is_active' => true,
        ];
    }

    /**
     * Каскад в PayrollCalculationService::resolveRate() берёт ПЕРВОЕ найденное
     * правило для точной комбинации (должность/сотрудник + услуга/категория/
     * по умолчанию + точка) — если таких правил два с разными ставками,
     * какое из них применится, непредсказуемо. Поэтому одна и та же комбинация
     * не может быть занята дважды среди активных правил.
     */
    private function assertNoDuplicate(array $data, ?int $excludeRuleId = null): void
    {
        $query = PayrollRule::where('is_active', true);

        foreach (['position_id', 'employee_id', 'service_id', 'service_category_id', 'branch_id'] as $column) {
            $data[$column] === null ? $query->whereNull($column) : $query->where($column, $data[$column]);
        }
        $query->where('is_default_for_unlisted', $data['is_default_for_unlisted']);

        if ($excludeRuleId) {
            $query->where('id', '!=', $excludeRuleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'target' => 'Такая ставка уже настроена для этой должности/сотрудника на эту услугу (группу/по умолчанию) и локацию. Отредактируйте существующую запись вместо создания дубликата.',
            ]);
        }
    }
}

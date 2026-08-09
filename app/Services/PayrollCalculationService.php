<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Service;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;

/**
 * Расчёт начислений ЗП по завершённому заказ-наряду (Фаза 10.2). Вызывается
 * из WorkOrderController::completeOrder(). Ничего не пишет в БД сама —
 * возвращает готовые строки для Payroll::create() и список пропущенных
 * начислений (сотрудник назначен, но ставка не настроена), чтобы контроллер
 * мог залогировать предупреждение через ActivityLogger, не блокируя
 * завершение заказа — донастройка ставок не должна становиться поводом
 * не закрыть заказ клиенту.
 *
 * Каскад приоритета ставки (первое найденное правило побеждает):
 *   1. Персональная ставка сотрудника на конкретную услугу
 *   2. Персональная ставка сотрудника на группу услуг
 *   3. Персональная ставка сотрудника по умолчанию (для услуг вне справочника)
 *   4. Ставка должности на конкретную услугу
 *   5. Ставка должности на группу услуг
 *   6. Ставка должности по умолчанию
 * На каждом уровне ставка точки (branch_id) приоритетнее общей (branch_id=null).
 *
 * Администратор позиции резолвится независимо от бригады исполнителей — один
 * и тот же сотрудник может одновременно быть админом позиции (начисление по
 * его основной должности) и физически состоять в бригаде исполнителей
 * (начисление по совмещаемой должности, если она задана, иначе по основной) —
 * это два отдельных начисления с разной ролью (admin/worker).
 */
class PayrollCalculationService
{
    /**
     * @return array{rows: array<int, array{employee_id:int, role:string, amount:int, work_order_item_id:int}>, skipped: array<int, string>}
     */
    public static function calculate(WorkOrder $workOrder): array
    {
        $settings = self::loadSettings();
        $rows = [];
        $skipped = [];

        $items = $workOrder->items()->with(['employees', 'materials', 'itemable'])->get();

        foreach ($items as $item) {
            if ($item->itemable_type !== Service::class) {
                continue;
            }

            $base = $settings['apply_discount_to_base']
                ? max(0, $item->total)
                : max(0, (int) round((float) $item->quantity * $item->price));

            $adminEmployee = self::resolveAdmin($workOrder, $item);
            $adminAccrual = 0;

            if ($adminEmployee) {
                $rate = self::resolveRate($adminEmployee, $adminEmployee->position, $item, $workOrder->branch_id);

                if (!$rate) {
                    $skipped[] = self::skipNote($adminEmployee, $item, 'не настроена ставка администратора');
                } elseif ($rate['type'] === 'fixed') {
                    $skipped[] = self::skipNote($adminEmployee, $item, 'администратору нельзя назначить фиксированную ставку — нужен процент');
                } else {
                    $adminAccrual = (int) round($base * (float) $rate['percentage_value'] / 100);
                    $adminAccrual = self::applySelfEmployedCompensation($adminEmployee, $adminAccrual, $settings);
                    $rows[] = self::row($adminEmployee, 'admin', $adminAccrual, $item);
                }
            }

            $materialsCost = $settings['worker_base_excludes_materials']
                ? (int) $item->materials->sum('total')
                : 0;

            $workerBase = $base - $materialsCost;
            if ($settings['worker_base_excludes_admin_share']) {
                $workerBase -= $adminAccrual;
            }
            $workerBase = max(0, $workerBase);

            $assignments = $item->employees;
            // Аутсорсеры не участвуют в долевом делении базы — у них фиксированная
            // сумма вне общего пула, поэтому не учитываются в знаменателе равной доли.
            $shareableCount = $assignments->where('type', '!=', 'outsource')->count();
            $anyShareSet = $assignments->where('type', '!=', 'outsource')->contains(fn (Employee $e) => $e->pivot->share_percent !== null);

            foreach ($assignments as $employee) {
                $share = $anyShareSet
                    ? (float) ($employee->pivot->share_percent ?? 0)
                    : ($shareableCount > 0 ? 100 / $shareableCount : 0);

                if ($employee->type === 'outsource') {
                    $amount = $employee->pivot->manual_amount_override !== null
                        ? (int) $employee->pivot->manual_amount_override
                        : self::resolveFixedOutsourceAmount($employee, $item, $workOrder->branch_id);

                    if ($amount === null) {
                        $skipped[] = self::skipNote($employee, $item, 'не указана сумма выплаты подрядчику');
                        continue;
                    }

                    $rows[] = self::row($employee, 'worker', $amount, $item);
                    continue;
                }

                if ($employee->pivot->manual_amount_override !== null) {
                    $amount = (int) $employee->pivot->manual_amount_override;
                } elseif ($employee->pivot->manual_percent_override !== null) {
                    $amount = (int) round($workerBase * $share / 100 * (float) $employee->pivot->manual_percent_override / 100);
                } else {
                    $position = $employee->secondary_position_id ? $employee->secondaryPosition : $employee->position;
                    $rate = self::resolveRate($employee, $position, $item, $workOrder->branch_id);

                    if (!$rate) {
                        $skipped[] = self::skipNote($employee, $item, 'не настроена ставка исполнителя');
                        continue;
                    }

                    if ($rate['type'] === 'fixed') {
                        $amount = (int) round($rate['fixed_amount'] * (float) $item->quantity * $share / 100);
                    } else {
                        $amount = (int) round($workerBase * $share / 100 * (float) $rate['percentage_value'] / 100);
                    }
                }

                $amount = self::applySelfEmployedCompensation($employee, $amount, $settings);
                $rows[] = self::row($employee, 'worker', $amount, $item);
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private static function resolveAdmin(WorkOrder $workOrder, WorkOrderItem $item): ?Employee
    {
        if ($item->admin_override === 'none') {
            return null;
        }

        $employeeId = $item->admin_override === 'custom'
            ? $item->admin_employee_id
            : $workOrder->default_admin_employee_id;

        return $employeeId ? Employee::find($employeeId) : null;
    }

    /**
     * @return array{type:string, fixed_amount:int, percentage_value:float}|null
     */
    private static function resolveRate(Employee $employee, ?Position $position, WorkOrderItem $item, ?int $branchId): ?array
    {
        $serviceId = $item->itemable_id;
        $serviceCategoryId = $item->itemable?->service_category_id;

        $candidates = [];

        if ($serviceId) {
            $candidates[] = ['employee_id' => $employee->id, 'service_id' => $serviceId, 'service_category_id' => null, 'position_id' => null, 'is_default_for_unlisted' => false];
        }
        if ($serviceCategoryId) {
            $candidates[] = ['employee_id' => $employee->id, 'service_id' => null, 'service_category_id' => $serviceCategoryId, 'position_id' => null, 'is_default_for_unlisted' => false];
        }
        $candidates[] = ['employee_id' => $employee->id, 'service_id' => null, 'service_category_id' => null, 'position_id' => null, 'is_default_for_unlisted' => true];

        if ($position) {
            if ($serviceId) {
                $candidates[] = ['employee_id' => null, 'service_id' => $serviceId, 'service_category_id' => null, 'position_id' => $position->id, 'is_default_for_unlisted' => false];
            }
            if ($serviceCategoryId) {
                $candidates[] = ['employee_id' => null, 'service_id' => null, 'service_category_id' => $serviceCategoryId, 'position_id' => $position->id, 'is_default_for_unlisted' => false];
            }
            $candidates[] = ['employee_id' => null, 'service_id' => null, 'service_category_id' => null, 'position_id' => $position->id, 'is_default_for_unlisted' => true];
        }

        foreach ($candidates as $conditions) {
            $query = PayrollRule::where('is_active', true)
                ->where('is_default_for_unlisted', $conditions['is_default_for_unlisted'])
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
                })
                ->orderByRaw('branch_id IS NULL');

            foreach (['employee_id', 'service_id', 'service_category_id', 'position_id'] as $col) {
                if ($conditions[$col] === null) {
                    $query->whereNull($col);
                } else {
                    $query->where($col, $conditions[$col]);
                }
            }

            $rule = $query->first();

            if ($rule) {
                return [
                    'type' => $rule->type,
                    'fixed_amount' => $rule->fixed_amount,
                    'percentage_value' => (float) $rule->percentage_value,
                ];
            }
        }

        return null;
    }

    private static function resolveFixedOutsourceAmount(Employee $employee, WorkOrderItem $item, ?int $branchId): ?int
    {
        $rate = self::resolveRate($employee, $employee->position, $item, $branchId);

        if ($rate && $rate['type'] === 'fixed') {
            return (int) round($rate['fixed_amount'] * (float) $item->quantity);
        }

        return null;
    }

    private static function applySelfEmployedCompensation(Employee $employee, int $amount, array $settings): int
    {
        if ($employee->type !== 'self_employed' || $amount <= 0) {
            return $amount;
        }

        $percent = $employee->self_employed_tax_percent !== null
            ? (float) $employee->self_employed_tax_percent
            : $settings['default_self_employed_tax_percent'];

        return (int) round($amount * (1 + $percent / 100));
    }

    private static function row(Employee $employee, string $role, int $amount, WorkOrderItem $item): array
    {
        return [
            'employee_id' => $employee->id,
            'role' => $role,
            'amount' => $amount,
            'work_order_item_id' => $item->id,
        ];
    }

    private static function skipNote(Employee $employee, WorkOrderItem $item, string $reason): string
    {
        $name = trim($employee->first_name . ' ' . $employee->last_name);

        return "{$name} — «{$item->name}»: {$reason}";
    }

    private static function loadSettings(): array
    {
        return [
            'apply_discount_to_base' => Setting::where('key', 'payroll_apply_discount_to_base')->value('value') !== '0',
            'worker_base_excludes_materials' => Setting::where('key', 'payroll_worker_base_excludes_materials')->value('value') !== '0',
            'worker_base_excludes_admin_share' => Setting::where('key', 'payroll_worker_base_excludes_admin_share')->value('value') === '1',
            'default_self_employed_tax_percent' => (float) (Setting::where('key', 'payroll_default_self_employed_tax_percent')->value('value') ?? 6),
        ];
    }
}

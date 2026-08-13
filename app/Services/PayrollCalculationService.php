<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\PayrollRule;
use App\Models\Position;
use App\Models\Service;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Collection;

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
 * Администраторы позиции резолвятся независимо от бригады исполнителей — один
 * и тот же сотрудник может одновременно быть админом позиции (начисление по
 * его основной должности) и физически состоять в бригаде исполнителей
 * (начисление по совмещаемой должности, если она задана, иначе по основной) —
 * это два отдельных начисления с разной ролью (admin/worker).
 *
 * Администраторов на позиции может быть несколько (work_order_admins /
 * work_order_item_admins, многие-ко-многим) — делят начисление между собой
 * по той же схеме share_percent/manual_amount_override/manual_percent_override,
 * что и бригада исполнителей: пустая доля = поровну между всеми админами.
 *
 * Исполнителем позиции может быть не только штатный сотрудник, но и Подрядчик
 * (Client с ролью «Подрядчик», см. work_order_item_assignees). Отличия расчёта
 * подрядчика от штатного исполнителя:
 *   - он вне общего пула долей: свою сумму получает целиком, не деля базу
 *     с бригадой (share_percent к нему не применяется);
 *   - у него нет должности, поэтому каскад ставки короче — только его
 *     персональные правила (client_id), без ступени «ставка должности»;
 *   - компенсация налога самозанятого к нему не применяется: подрядчик
 *     выставляет свою цену сам, гросс-ап тут был бы двойным начислением.
 * Бизнес-правило «штатные и подрядчики не смешиваются на одной позиции»
 * обеспечивает WorkOrderController; расчёт намеренно не полагается на него и
 * корректно обработает обе коллекции, если инвариант когда-то нарушат.
 */
class PayrollCalculationService
{
    /**
     * @return array{rows: array<int, array{employee_id:?int, client_id:?int, role:string, amount:int, work_order_item_id:int}>, skipped: array<int, string>}
     */
    public static function calculate(WorkOrder $workOrder): array
    {
        $settings = self::loadSettings();
        $rows = [];
        $skipped = [];

        $workOrder->loadMissing('admins');
        $items = $workOrder->items()->with(['employees', 'contractors', 'admins', 'materials', 'itemable'])->get();

        foreach ($items as $item) {
            if ($item->itemable_type !== Service::class) {
                continue;
            }

            $base = $settings['apply_discount_to_base']
                ? max(0, $item->total)
                : max(0, (int) round((float) $item->quantity * $item->price));

            $admins = self::resolveAdmins($workOrder, $item);
            $adminAccrual = 0;

            $adminShareableCount = $admins->count();
            $adminAnyShareSet = $admins->contains(fn (Employee $e) => $e->pivot->share_percent !== null);

            foreach ($admins as $adminEmployee) {
                $adminShare = $adminAnyShareSet
                    ? (float) ($adminEmployee->pivot->share_percent ?? 0)
                    : ($adminShareableCount > 0 ? 100 / $adminShareableCount : 0);

                if ($adminEmployee->pivot->manual_amount_override !== null) {
                    $amount = (int) $adminEmployee->pivot->manual_amount_override;
                } elseif ($adminEmployee->pivot->manual_percent_override !== null) {
                    $amount = (int) round($base * $adminShare / 100 * (float) $adminEmployee->pivot->manual_percent_override / 100);
                } else {
                    $rate = self::resolveRate($adminEmployee, $adminEmployee->position, $item, $workOrder->branch_id);

                    if (! $rate) {
                        $skipped[] = self::skipNote($adminEmployee, $item, 'не настроена ставка администратора');

                        continue;
                    }

                    if ($rate['type'] === 'fixed') {
                        $skipped[] = self::skipNote($adminEmployee, $item, 'администратору нельзя назначить фиксированную ставку — нужен процент');

                        continue;
                    }

                    $amount = (int) round($base * $adminShare / 100 * (float) $rate['percentage_value'] / 100);
                }

                $amount = self::applySelfEmployedCompensation($adminEmployee, $amount, $settings);
                $adminAccrual += $amount;
                $rows[] = self::row($adminEmployee, 'admin', $amount, $item);
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
            // Все штатные исполнители позиции делят базу между собой: внешние
            // подрядчики сюда не попадают в принципе (они в $item->contractors,
            // отдельная связь), поэтому исключений из знаменателя больше нет.
            $shareableCount = $assignments->count();
            $anyShareSet = $assignments->contains(fn (Employee $e) => $e->pivot->share_percent !== null);

            foreach ($assignments as $employee) {
                $share = $anyShareSet
                    ? (float) ($employee->pivot->share_percent ?? 0)
                    : ($shareableCount > 0 ? 100 / $shareableCount : 0);

                if ($employee->pivot->manual_amount_override !== null) {
                    $amount = (int) $employee->pivot->manual_amount_override;
                } elseif ($employee->pivot->manual_percent_override !== null) {
                    $amount = (int) round($workerBase * $share / 100 * (float) $employee->pivot->manual_percent_override / 100);
                } else {
                    $position = $employee->secondary_position_id ? $employee->secondaryPosition : $employee->position;
                    $rate = self::resolveRate($employee, $position, $item, $workOrder->branch_id);

                    if (! $rate) {
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

            // Подрядчики — вне пула долей: каждый получает свою сумму целиком,
            // поэтому здесь нет ни share_percent, ни знаменателя равной доли.
            foreach ($item->contractors as $contractor) {
                if ($contractor->pivot->manual_amount_override !== null) {
                    $amount = (int) $contractor->pivot->manual_amount_override;
                } elseif ($contractor->pivot->manual_percent_override !== null) {
                    $amount = (int) round($workerBase * (float) $contractor->pivot->manual_percent_override / 100);
                } else {
                    $rate = self::resolveRateForContractor($contractor, $item, $workOrder->branch_id);

                    if (! $rate) {
                        $skipped[] = self::skipNote($contractor, $item, 'не настроена ставка подрядчика и не указана сумма вручную');

                        continue;
                    }

                    $amount = $rate['type'] === 'fixed'
                        ? (int) round($rate['fixed_amount'] * (float) $item->quantity)
                        : (int) round($workerBase * (float) $rate['percentage_value'] / 100);
                }

                $rows[] = self::row($contractor, 'worker', $amount, $item);
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * @return Collection<int, Employee>
     */
    private static function resolveAdmins(WorkOrder $workOrder, WorkOrderItem $item): Collection
    {
        if ($item->admin_override === 'none') {
            return new Collection;
        }

        return $item->admin_override === 'custom'
            ? $item->admins
            : $workOrder->admins;
    }

    /**
     * Полный набор колонок-«адресов» правила. Кандидат обязан задавать ВСЕ из
     * них (незаданные явно = NULL), иначе поиск ставки сотрудника мог бы
     * случайно подобрать правило подрядчика и наоборот: недостающая колонка
     * просто не попала бы в WHERE и перестала различать эти два случая.
     */
    private const RULE_TARGET_COLUMNS = ['employee_id', 'client_id', 'position_id', 'service_id', 'service_category_id'];

    /**
     * @return array{type:string, fixed_amount:int, percentage_value:float}|null
     */
    private static function resolveRate(Employee $employee, ?Position $position, WorkOrderItem $item, ?int $branchId): ?array
    {
        $serviceId = $item->itemable_id;
        $serviceCategoryId = $item->itemable?->service_category_id;

        $candidates = [];

        if ($serviceId) {
            $candidates[] = self::ruleCandidate(['employee_id' => $employee->id, 'service_id' => $serviceId]);
        }
        if ($serviceCategoryId) {
            $candidates[] = self::ruleCandidate(['employee_id' => $employee->id, 'service_category_id' => $serviceCategoryId]);
        }
        $candidates[] = self::ruleCandidate(['employee_id' => $employee->id, 'is_default_for_unlisted' => true]);

        if ($position) {
            if ($serviceId) {
                $candidates[] = self::ruleCandidate(['position_id' => $position->id, 'service_id' => $serviceId]);
            }
            if ($serviceCategoryId) {
                $candidates[] = self::ruleCandidate(['position_id' => $position->id, 'service_category_id' => $serviceCategoryId]);
            }
            $candidates[] = self::ruleCandidate(['position_id' => $position->id, 'is_default_for_unlisted' => true]);
        }

        return self::firstMatchingRule($candidates, $branchId);
    }

    /**
     * Каскад для подрядчика короче, чем для сотрудника: у него нет должности,
     * поэтому ступени «ставка должности» не существует в принципе — только
     * его персональные правила (client_id).
     *
     * @return array{type:string, fixed_amount:int, percentage_value:float}|null
     */
    private static function resolveRateForContractor(Client $contractor, WorkOrderItem $item, ?int $branchId): ?array
    {
        $serviceId = $item->itemable_id;
        $serviceCategoryId = $item->itemable?->service_category_id;

        $candidates = [];

        if ($serviceId) {
            $candidates[] = self::ruleCandidate(['client_id' => $contractor->id, 'service_id' => $serviceId]);
        }
        if ($serviceCategoryId) {
            $candidates[] = self::ruleCandidate(['client_id' => $contractor->id, 'service_category_id' => $serviceCategoryId]);
        }
        $candidates[] = self::ruleCandidate(['client_id' => $contractor->id, 'is_default_for_unlisted' => true]);

        return self::firstMatchingRule($candidates, $branchId);
    }

    private static function ruleCandidate(array $target): array
    {
        return array_merge(
            array_fill_keys(self::RULE_TARGET_COLUMNS, null),
            ['is_default_for_unlisted' => false],
            $target,
        );
    }

    /**
     * @return array{type:string, fixed_amount:int, percentage_value:float}|null
     */
    private static function firstMatchingRule(array $candidates, ?int $branchId): ?array
    {
        foreach ($candidates as $conditions) {
            $query = PayrollRule::where('is_active', true)
                ->where('is_default_for_unlisted', $conditions['is_default_for_unlisted'])
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
                })
                ->orderByRaw('branch_id IS NULL');

            foreach (self::RULE_TARGET_COLUMNS as $col) {
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

    /**
     * Строка будущего Payroll. Заполнена ровно одна из employee_id/client_id —
     * этот же инвариант зафиксирован в схеме payrolls (см. миграцию
     * 2027_01_31_000001) и в Payroll::payee().
     */
    private static function row(Employee|Client $payee, string $role, int $amount, WorkOrderItem $item): array
    {
        return [
            'employee_id' => $payee instanceof Employee ? $payee->id : null,
            'client_id' => $payee instanceof Client ? $payee->id : null,
            'role' => $role,
            'amount' => $amount,
            'work_order_item_id' => $item->id,
        ];
    }

    private static function skipNote(Employee|Client $payee, WorkOrderItem $item, string $reason): string
    {
        $name = $payee instanceof Employee
            ? trim($payee->first_name.' '.$payee->last_name)
            : $payee->name;

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

<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Setting;
use App\Services\TimezoneResolver;
use Illuminate\Console\Command;
use Carbon\Carbon;

/**
 * Начисляет оклад (Payroll: role=salary, status=pending) активным сотрудникам
 * с заданным employees.salary_amount. Запускается ЕЖЕЧАСНО через
 * `tenants:run payroll:accrue-salaries` (см. bootstrap/app.php) — не
 * dailyAt('00:00'), по той же причине, что и TakeDailyAccountSnapshots:
 * точки тенанта могут быть в разных часовых поясах. Команда сама проверяет,
 * у каких поясов сейчас полночь И совпадает ли сегодняшнее число с
 * настроенным днём начисления (Setting payroll_salary_accrual_day).
 *
 * Начисление — только черновик (status=pending): реальную выплату (списание
 * с кассы через FinanceService) менеджер подтверждает вручную в карточке
 * сотрудника — см. PayrollController::payout().
 *
 * Идемпотентность: за один календарный месяц на сотрудника создаётся не
 * более одной строки role=salary — повторный запуск (в том числе ручной
 * бэкафилл на ту же дату) не задваивает начисление.
 *
 * Ручной бэкафилл (игнорирует проверку "сейчас ли день начисления"):
 *   php artisan tenants:run payroll:accrue-salaries --tenants=client1 --option=date=2026-03-01
 */
class AccruePayrollSalaries extends Command
{
    protected $signature = 'payroll:accrue-salaries {--date= : Ручной бэкафилл на конкретную дату (по всем поясам, без проверки дня начисления)}';
    protected $description = 'Начислить оклад активным сотрудникам с заданным окладом (по дню начисления из настроек, с учётом часового пояса точек)';

    public function handle(): int
    {
        $explicitDate = $this->option('date');
        $tenantTimezone = TimezoneResolver::forTenant();
        $accrualDay = (int) (Setting::where('key', 'payroll_salary_accrual_day')->value('value') ?? 1);

        $timezones = Branch::whereNotNull('timezone')
            ->distinct()
            ->pluck('timezone')
            ->push($tenantTimezone)
            ->unique()
            ->values();

        $totalAccrued = 0;

        foreach ($timezones as $timezone) {
            $now = $explicitDate ? Carbon::parse($explicitDate, $timezone) : Carbon::now($timezone);

            if (!$explicitDate && ($now->hour !== 0 || $now->day !== $accrualDay)) {
                continue;
            }

            $employees = Employee::where('is_active', true)
                ->whereNotNull('salary_amount')
                ->where(function ($query) use ($timezone, $tenantTimezone) {
                    $query->whereHas('branch', fn ($b) => $b->where('timezone', $timezone));

                    if ($timezone === $tenantTimezone) {
                        $query->orWhere(function ($fallback) {
                            $fallback->whereNull('branch_id')
                                ->orWhereHas('branch', fn ($b) => $b->whereNull('timezone'));
                        });
                    }
                })
                ->get();

            $periodComment = 'Оклад за ' . $now->translatedFormat('F Y');

            foreach ($employees as $employee) {
                // Ключ идемпотентности — целевой период (закодирован в комментарии),
                // а не created_at строки: при ручном бэкафилле за прошлый месяц
                // created_at всегда "сейчас реальное", а не дата периода, так что
                // сравнение по created_at ошибочно посчитало бы повторный бэкафилл
                // того же прошлого периода "новым" и задвоило бы начисление.
                $alreadyAccrued = Payroll::where('employee_id', $employee->id)
                    ->where('role', 'salary')
                    ->where('comment', $periodComment)
                    ->exists();

                if ($alreadyAccrued) {
                    continue;
                }

                Payroll::create([
                    'employee_id' => $employee->id,
                    'branch_id' => $employee->branch_id,
                    'type' => 'accrual',
                    'role' => 'salary',
                    'amount' => $employee->salary_amount,
                    'status' => 'pending',
                    'comment' => $periodComment,
                ]);

                $totalAccrued++;
            }
        }

        $this->info("Начислено окладов: {$totalAccrued}");

        return self::SUCCESS;
    }
}

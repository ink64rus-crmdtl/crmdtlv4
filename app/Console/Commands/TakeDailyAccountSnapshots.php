<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Branch;
use App\Services\AccountSnapshotService;
use App\Services\TimezoneResolver;
use Illuminate\Console\Command;
use Carbon\Carbon;

/**
 * Считает дневные снэпшоты остатков (открытие/обороты/закрытие) по всем счетам текущего тенанта.
 * Запускается КАЖДЫЙ ЧАС через `tenants:run snapshots:accounts` (см. bootstrap/app.php) — не в
 * фиксированные "00:00 по серверу", потому что филиалы одного тенанта могут быть в разных
 * часовых поясах (Branch::timezone), а сервер работает в UTC. На каждом запуске команда сама
 * проверяет, у каких часовых поясов прямо сейчас только что наступила полночь, и считает
 * снэпшот "за вчера" только для счетов, относящихся к этим поясам.
 *
 * Ручной бэкафилл (игнорирует проверку "сейчас ли полночь", считает сразу для всех счетов):
 *   php artisan tenants:run snapshots:accounts --tenants=client1 --option=date=2026-03-01
 */
class TakeDailyAccountSnapshots extends Command
{
    protected $signature = 'snapshots:accounts {--date= : Ручной бэкафилл на конкретную дату (по всем счетам, без проверки часового пояса)}';
    protected $description = 'Посчитать дневные снэпшоты остатков по счетам текущего тенанта, с учетом часового пояса филиалов';

    public function handle(): int
    {
        $explicitDate = $this->option('date');
        $tenantTimezone = TimezoneResolver::forTenant();

        // Все часовые пояса, реально используемые филиалами тенанта, плюс пояс тенанта по
        // умолчанию (покрывает счета без филиала и филиалы с незаполненным timezone).
        $timezones = Branch::whereNotNull('timezone')
            ->distinct()
            ->pluck('timezone')
            ->push($tenantTimezone)
            ->unique()
            ->values();

        $totalAccounts = 0;

        foreach ($timezones as $timezone) {
            if (!$explicitDate && Carbon::now($timezone)->hour !== 0) {
                // Не бэкафилл — обрабатываем пояс только в тот час, когда в нем только что наступила полночь.
                continue;
            }

            $date = $explicitDate ?: Carbon::now($timezone)->subDay()->toDateString();

            $accountIds = Account::where('is_active', true)
                ->where(function ($query) use ($timezone, $tenantTimezone) {
                    $query->whereHas('branch', fn ($b) => $b->where('timezone', $timezone));

                    if ($timezone === $tenantTimezone) {
                        $query->orWhere(function ($fallback) {
                            $fallback->whereNull('branch_id')
                                ->orWhereHas('branch', fn ($b) => $b->whereNull('timezone'));
                        });
                    }
                })
                ->pluck('id');

            foreach ($accountIds as $accountId) {
                AccountSnapshotService::recomputeForDate($accountId, $date);
            }

            if ($accountIds->count() > 0) {
                $this->info("Пояс {$timezone}: {$accountIds->count()} счет(ов), дата {$date}");
            }

            $totalAccounts += $accountIds->count();
        }

        $this->info("Итого посчитано снэпшотов: {$totalAccounts}");

        return self::SUCCESS;
    }
}

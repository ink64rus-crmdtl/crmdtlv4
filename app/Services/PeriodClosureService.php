<?php

namespace App\Services;

use App\Models\FinancePeriodClosure;
use Carbon\Carbon;
use Exception;

class PeriodClosureService
{
    /**
     * Дата, по которую (включительно) финансовый период закрыт. Null, если ничего не закрыто.
     */
    public static function closedThroughDate(): ?string
    {
        $date = FinancePeriodClosure::orderByDesc('period_end_date')->value('period_end_date');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /**
     * Бросает исключение, если дата операции попадает в уже закрытый период.
     * Используется в начале всех точек входа FinanceService — единая точка проверки.
     */
    public static function assertNotClosed(?string $date): void
    {
        if (!$date) {
            return;
        }

        $closedThrough = self::closedThroughDate();

        if ($closedThrough && Carbon::parse($date)->lte(Carbon::parse($closedThrough))) {
            throw new Exception("Период закрыт по {$closedThrough} — операции с датой {$date} недоступны. Обратитесь к разделу «Закрытие периода».");
        }
    }
}

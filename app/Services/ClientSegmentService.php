<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * RFM-сегментация клиентов (Фаза 14.3) — упрощённая, три именованных
 * сегмента поверх Recency/Frequency вместо полной 5x5x5 RFM-матрицы
 * квантилей: этого достаточно для "выделения Спящих/Лояльных/Новичков"
 * без отдельной аналитической инфраструктуры (та — предмет Фазы 13).
 * Monetary (segment_orders_sum) считается и отдаётся, но пока не участвует
 * в классификации — используется в другом виде (LTV) при оживлении
 * KPI-дашборда клиента в Фазе 13.
 */
class ClientSegmentService
{
    public const DORMANT_DAYS = 90;
    public const LOYAL_MIN_ORDERS = 3;

    public const SEGMENTS = [
        'new' => 'Новичок',
        'regular' => 'Обычный',
        'loyal' => 'Лояльный',
        'dormant' => 'Спящий',
    ];

    /**
     * Подмешивает агрегаты по завершённым заказам клиента через
     * withCount/withMax/withSum (SQL-подзапросы в SELECT) — без ->get() по
     * списку клиентов (запрещено CLAUDE.md п.6), сегмент считается уже
     * после пагинации, по этим готовым числам конкретной страницы.
     */
    public static function withAggregates(Builder $query): Builder
    {
        return $query
            ->withCount(['workOrders as segment_orders_count' => fn (Builder $q) => $q->where('status', 'completed')])
            ->withMax(['workOrders as segment_last_order_at' => fn (Builder $q) => $q->where('status', 'completed')], 'created_at')
            ->withSum(['workOrders as segment_orders_sum' => fn (Builder $q) => $q->where('status', 'completed')], 'final_amount');
    }

    public static function classify(Client $client): string
    {
        return self::classifyFromCounts((int) ($client->segment_orders_count ?? 0), $client->segment_last_order_at);
    }

    /**
     * То же самое, но по уже готовым числам — для карточки одного клиента
     * (ClientController::show()), где список его заказов и так уже выгружен
     * для вкладки "Заказы", повторный агрегатный запрос не нужен.
     */
    public static function classifyFromCounts(int $ordersCount, mixed $lastOrderAt): string
    {
        if ($ordersCount === 0) {
            return 'new';
        }

        if ($lastOrderAt && Carbon::parse($lastOrderAt)->diffInDays(now()) >= self::DORMANT_DAYS) {
            return 'dormant';
        }

        if ($ordersCount >= self::LOYAL_MIN_ORDERS) {
            return 'loyal';
        }

        if ($ordersCount === 1) {
            return 'new';
        }

        return 'regular';
    }

    public static function label(string $segment): string
    {
        return self::SEGMENTS[$segment] ?? $segment;
    }

    /**
     * Серверная фильтрация по сегменту через HAVING на агрегатах из
     * withAggregates() — зеркалит classify() на уровне SQL, чтобы фильтр
     * применялся ДО пагинации, а не после (->get() для списков запрещён).
     */
    public static function applyFilter(Builder $query, string $segment): Builder
    {
        $dormantBoundary = now()->subDays(self::DORMANT_DAYS);

        // "Спящий" по classify() выигрывает у любого другого сегмента при
        // просроченной давности, даже если по счётчику заказов запись
        // выглядела бы как "Новичок" (ordersCount<=1) — фильтр обязан это
        // зеркалить, иначе один и тот же клиент попадал бы сразу в 2 сегмента.
        $notDormant = fn (QueryBuilder $q) => $q->havingNull('segment_last_order_at')->orHaving('segment_last_order_at', '>=', $dormantBoundary);

        return match ($segment) {
            'new' => $query->having('segment_orders_count', '<=', 1)->having($notDormant),
            'dormant' => $query->having('segment_orders_count', '>=', 1)
                ->having('segment_last_order_at', '<', $dormantBoundary),
            'loyal' => $query->having('segment_orders_count', '>=', self::LOYAL_MIN_ORDERS)->having($notDormant),
            'regular' => $query->having('segment_orders_count', '=', 2)->having($notDormant),
            default => $query,
        };
    }
}

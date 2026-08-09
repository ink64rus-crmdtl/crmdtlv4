<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\WorkOrder;

/**
 * Гибкая система лояльности (продолжение Фазы 14.1): грейд клиента
 * (ClientGroup) может подбираться автоматически по обороту и/или количеству
 * завершённых заказов за настраиваемый период — пороги задаются per-группа
 * (Settings/Loyalty), а не зашиты в код. Ручной выбор группы (через форму
 * клиента) блокирует автоподбор для конкретного клиента — см.
 * Client.client_group_locked, тот же принцип приоритета, что и у
 * "Индивидуальные права Пользователя перекрывают права Роли" (CLAUDE.md §5).
 */
class LoyaltyGradeService
{
    /**
     * Переоценивает грейд клиента. Группы проверяются по sort_order
     * (по возрастанию — меньше значение, выше приоритет), первая подошедшая
     * побеждает. Если ни одна группа с правилом не подошла — группу клиента
     * НЕ трогаем (не сбрасываем на "без группы"): резкое обнуление грейда
     * из-за временного затишья хуже, чем оставить последний известный статус;
     * понижение происходит естественно, если клиент попадает под условия
     * более младшей группы.
     */
    public static function evaluate(Client $client): void
    {
        if ($client->client_group_locked) {
            return;
        }

        $groups = ClientGroup::where(function ($q) {
                $q->whereNotNull('min_turnover_amount')->orWhereNotNull('min_orders_count');
            })
            ->orderBy('sort_order')
            ->get();

        foreach ($groups as $group) {
            if (self::clientQualifies($client, $group)) {
                if ($client->client_group_id !== $group->id) {
                    $client->update(['client_group_id' => $group->id]);
                }
                return;
            }
        }
    }

    private static function clientQualifies(Client $client, ClientGroup $group): bool
    {
        $periodDays = $group->auto_assign_period_days ?? 90;
        $since = now()->subDays($periodDays);

        $baseQuery = fn () => WorkOrder::where('client_id', $client->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $since);

        $turnoverOk = is_null($group->min_turnover_amount)
            || $baseQuery()->sum('final_amount') >= $group->min_turnover_amount;

        $ordersOk = is_null($group->min_orders_count)
            || $baseQuery()->count() >= $group->min_orders_count;

        return $turnoverOk && $ordersOk;
    }

    /**
     * Эффективный процент скидки клиента для расчёта суммы заказа —
     * собственный Client.discount_percent приоритетнее группового (та же
     * логика приоритета "своё важнее общего", что и у cashback НЕ работает
     * симметрично: кэшбек всегда только групповой, т.к. индивидуального
     * cashback_percent у Client просто нет — только у скидки есть оба уровня).
     */
    public static function resolveDiscountPercent(Client $client): float
    {
        if ($client->discount_percent > 0) {
            return (float) $client->discount_percent;
        }

        return (float) ($client->group?->discount_percent ?? 0);
    }
}

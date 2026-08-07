<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Тонкая обёртка над spatie/laravel-activitylog для ленты "История" на
 * полных карточках (Клиент/Авто/Сотрудник/Заказ-наряд). Осознанно НЕ
 * используется автологирование пакета (LogsActivity trait) — оно даёт
 * технический шум вида "branch_id изменён с 3 на 5". Вместо этого запись
 * делается вручную, в конкретных точках контроллеров, человеческим текстом
 * на русском — как у HubSpot/Pipedrive и т.п.
 *
 * $links — необязательные ссылки на связанные записи, отображаются во
 * фронтенде отдельными чипами под описанием события (не парсим ссылки из
 * текста — надёжнее хранить структурированно). Пример:
 *   ActivityLogger::log($workOrder, 'Заказ создан из записи в календаре', [
 *       ['type' => 'appointment', 'id' => $appointment->id, 'label' => 'Открыть запись'],
 *   ], 'created');
 */
class ActivityLogger
{
    /**
     * @param array<int, array{type: string, id: int, label: string}> $links
     */
    public static function log(Model $subject, string $description, array $links = [], ?string $event = null, array $properties = []): void
    {
        // Автоматически прокидываем client_id/vehicle_id из самой записи (если
        // они есть) в properties — это даёт roll-up: лента Клиента/Автомобиля
        // подтягивает не только свои события, но и события связанных Записей
        // и Заказ-нарядов, без необходимости передавать это вручную на каждом
        // вызове (см. ClientController/VehicleController::show()).
        foreach (['client_id', 'vehicle_id'] as $rollupField) {
            if (!array_key_exists($rollupField, $properties) && !empty($subject->{$rollupField})) {
                $properties[$rollupField] = $subject->{$rollupField};
            }
        }

        $builder = activity()
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->withProperties(array_merge($properties, ['links' => $links]));

        if ($event) {
            $builder->event($event);
        }

        $builder->log($description);
    }

    /**
     * Лента событий для $subject: свои события + (если передан $rollupField,
     * например 'client_id' или 'vehicle_id') события связанных Записей и
     * Заказ-нарядов, где это поле указывает на $subject — так карточка
     * Клиента/Автомобиля показывает не только собственные события, но и всё,
     * что произошло по его заказам/записям (как roll-up в HubSpot).
     */
    public static function feedFor(Model $subject, ?string $rollupField = null): Collection
    {
        $query = Activity::query()->where(function ($q) use ($subject) {
            $q->where('subject_type', get_class($subject))->where('subject_id', $subject->getKey());
        });

        if ($rollupField) {
            $query->orWhere(function ($q) use ($rollupField, $subject) {
                $q->whereIn('subject_type', [WorkOrder::class, Appointment::class])
                    ->where("properties->{$rollupField}", $subject->getKey());
            });
        }

        return $query->with('causer')->latest()->get();
    }

    /**
     * Разбивает ленту на "Историю" (системные события) и "Комментарии" —
     * см. CLAUDE.md, п.7: комментарии менеджеров всегда отдельная вкладка.
     *
     * @return array{activities: array, comments: array}
     */
    public static function present(Collection $items): array
    {
        $mapped = $items->map(fn (Activity $a) => [
            'id' => $a->id,
            'description' => $a->description,
            'event' => $a->event,
            'causer' => $a->causer ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
            'properties' => $a->properties,
            'created_at' => $a->created_at,
        ]);

        return [
            'activities' => $mapped->where('event', '!=', 'comment')->values()->all(),
            'comments' => $mapped->where('event', 'comment')->values()->all(),
        ];
    }
}

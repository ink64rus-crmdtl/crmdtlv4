<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserScopeCachingService
{
    /**
     * Получает массив доступных ID для указанного типа Scope из кэша.
     */
    public static function getScopes(User $user, string $type): array
    {
        // Кэш автоматически изолируется по тенанту благодаря CacheTenancyBootstrapper
        $scopes = Cache::remember("user_{$user->id}_scopes", 86400, function () use ($user) {
            return self::cacheScopes($user);
        });

        return $scopes[$type] ?? [];
    }

    /**
     * Вычисляет и кэширует все Scopes для пользователя.
     */
    public static function cacheScopes(User $user): array
    {
        $roleIds = $user->roles()->pluck('id')->toArray();
        $isAdmin = $user->isAdmin();

        $scopes = [
            'branches' => self::computeScope($user, 'branches', 'branch_id', 'role_branches', $roleIds, $isAdmin),
            'legal_entities' => self::computeScope($user, 'legalEntities', 'legal_entity_id', 'role_legal_entities', $roleIds, $isAdmin),
            'business_directions' => self::computeScope($user, 'businessDirections', 'business_direction_id', 'role_business_directions', $roleIds, $isAdmin),
            'warehouses' => self::computeScope($user, 'warehouses', 'warehouse_id', 'role_warehouses', $roleIds, $isAdmin),
            'accounts' => self::computeScope($user, 'accounts', 'account_id', 'role_accounts', $roleIds, $isAdmin),
        ];

        Cache::put("user_{$user->id}_scopes", $scopes, 86400); // Кэшируем на 24 часа

        return $scopes;
    }

    /**
     * Принудительно очищает кэш Scopes пользователя (например, при смене прав).
     */
    public static function clearScopes(User $user): void
    {
        Cache::forget("user_{$user->id}_scopes");
    }

    /**
     * Внутренняя логика вычисления доступных ID.
     * Приоритет: Admin -> Индивидуальные права (User) -> Права должности (Role).
     */
    private static function computeScope(User $user, string $relation, string $foreignKey, string $pivotTable, array $roleIds, bool $isAdmin): array
    {
        if ($isAdmin) {
            return ['*']; // Специальный маркер полного доступа
        }

        // Проверяем индивидуальные права пользователя
        if ($user->$relation()->exists()) {
            $tableName = $user->$relation()->getRelated()->getTable();
            return $user->$relation()->pluck($tableName . '.id')->toArray();
        }

        // Если индивидуальных нет, берем права из ролей
        return DB::table($pivotTable)
            ->whereIn('role_id', $roleIds)
            ->pluck($foreignKey)
            ->unique()
            ->toArray();
    }
}
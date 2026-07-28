<?php

namespace App\Services;

use App\Models\FieldPermission;
use Illuminate\Support\Facades\Cache;

class FieldPermissionService
{
    /**
     * Возвращает массив ключей полей, которые пользователю разрешено видеть.
     */
    public static function visibleFields($user, string $entityType, array $allFields): array
    {
        if (!$user) return [];
        if ($user->hasRole('admin')) return $allFields; // Админ видит всё

        $permissions = self::getPermissions($user, $entityType);
        
        return array_filter($allFields, function($field) use ($permissions) {
            return $permissions[$field]['can_view'] ?? true; // По умолчанию true, если нет явного запрета
        });
    }

    /**
     * Возвращает массив ключей полей, которые пользователю разрешено редактировать.
     */
    public static function editableFields($user, string $entityType, array $allFields): array
    {
        if (!$user) return [];
        if ($user->hasRole('admin')) return $allFields; // Админ редактирует всё

        $permissions = self::getPermissions($user, $entityType);
        
        return array_filter($allFields, function($field) use ($permissions) {
            return $permissions[$field]['can_edit'] ?? true; // По умолчанию true, если нет явного запрета
        });
    }

    /**
     * Получает и кэширует матрицу прав для пользователя и сущности.
     */
    protected static function getPermissions($user, string $entityType): array
    {
        $roleIds = $user->roles->pluck('id')->toArray();
        if (empty($roleIds)) return [];

        // Кэш-ключ автоматически изолирован тенантом благодаря CacheTenancyBootstrapper
        $cacheKey = "field_permissions_{$entityType}_" . implode('_', $roleIds);

        return Cache::remember($cacheKey, 3600, function () use ($roleIds, $entityType) {
            $perms = FieldPermission::whereIn('role_id', $roleIds)
                ->where('entity_type', $entityType)
                ->get();

            $result = [];
            foreach ($perms as $perm) {
                if (!isset($result[$perm->field_key])) {
                    $result[$perm->field_key] = [
                        'can_view' => $perm->can_view,
                        'can_edit' => $perm->can_edit,
                    ];
                } else {
                    // Если у пользователя несколько ролей, разрешаем доступ, если хотя бы одна роль разрешает (OR)
                    $result[$perm->field_key]['can_view'] = $result[$perm->field_key]['can_view'] || $perm->can_view;
                    $result[$perm->field_key]['can_edit'] = $result[$perm->field_key]['can_edit'] || $perm->can_edit;
                }
            }
            return $result;
        });
    }
}
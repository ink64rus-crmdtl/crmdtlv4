<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Общеплатформенные настройки (central БД) — ключ/значение, значение
 * зашифровано (encrypted cast), т.к. первое применение — общий токен
 * Wappi.Pro. Читать из tenant-контекста ТОЛЬКО через tenancy()->central(...)
 * (см. WappiProProvider) — обычный запрос из тенантского запроса попадёт
 * в текущую БД тенанта, где этой таблицы нет.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    public static function get(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

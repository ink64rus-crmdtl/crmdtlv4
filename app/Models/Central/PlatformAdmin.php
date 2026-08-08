<?php

namespace App\Models\Central;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Администратор платформы — central (landlord) БД, гвард 'platform_admin'
 * (config/auth.php). Намеренно НЕ переиспользует App\Models\User: тот
 * тащит тенантские трейты/связи (роли, Employee и т.п.), не имеющие
 * смысла в central-контексте. Нет публичной регистрации — заводится
 * только через artisan-команду `platform:create-admin`.
 */
class PlatformAdmin extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}

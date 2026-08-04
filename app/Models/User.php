<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Services\UserScopeCachingService;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    // --- Прямые связи (Приоритет 1: Индивидуальные настройки пользователя) ---

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    public function legalEntities(): BelongsToMany
    {
        return $this->belongsToMany(LegalEntity::class, 'user_legal_entities');
    }

    public function businessDirections(): BelongsToMany
    {
        return $this->belongsToMany(BusinessDirection::class, 'user_business_directions');
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouses');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'user_accounts');
    }

    // --- Логика доступности (Admin -> User Scopes -> Role Scopes) ---

    public function availableBranches()
    {
        $ids = UserScopeCachingService::getScopes($this, 'branches');
        return in_array('*', $ids) ? Branch::query() : Branch::whereIn('id', $ids);
    }

    public function availableLegalEntities()
    {
        $ids = UserScopeCachingService::getScopes($this, 'legal_entities');
        return in_array('*', $ids) ? LegalEntity::query() : LegalEntity::whereIn('id', $ids);
    }

    public function availableBusinessDirections()
    {
        $ids = UserScopeCachingService::getScopes($this, 'business_directions');
        return in_array('*', $ids) ? BusinessDirection::query() : BusinessDirection::whereIn('id', $ids);
    }

    public function availableWarehouses()
    {
        $ids = UserScopeCachingService::getScopes($this, 'warehouses');
        return in_array('*', $ids) ? Warehouse::query() : Warehouse::whereIn('id', $ids);
    }

    public function availableAccounts()
    {
        $ids = UserScopeCachingService::getScopes($this, 'accounts');
        return in_array('*', $ids) ? Account::query() : Account::whereIn('id', $ids);
    }
}
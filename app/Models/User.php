<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        if ($this->isAdmin()) {
            return Branch::query();
        }

        if ($this->branches()->exists()) {
            return $this->branches();
        }

        $roleIds = $this->roles()->pluck('id');
        return Branch::whereIn('id', function ($query) use ($roleIds) {
            $query->select('branch_id')
                  ->from('role_branches')
                  ->whereIn('role_id', $roleIds);
        });
    }

    public function availableLegalEntities()
    {
        if ($this->isAdmin()) {
            return LegalEntity::query();
        }

        if ($this->legalEntities()->exists()) {
            return $this->legalEntities();
        }

        $roleIds = $this->roles()->pluck('id');
        return LegalEntity::whereIn('id', function ($query) use ($roleIds) {
            $query->select('legal_entity_id')
                  ->from('role_legal_entities')
                  ->whereIn('role_id', $roleIds);
        });
    }

    public function availableBusinessDirections()
    {
        if ($this->isAdmin()) {
            return BusinessDirection::query();
        }

        if ($this->businessDirections()->exists()) {
            return $this->businessDirections();
        }

        $roleIds = $this->roles()->pluck('id');
        return BusinessDirection::whereIn('id', function ($query) use ($roleIds) {
            $query->select('business_direction_id')
                  ->from('role_business_directions')
                  ->whereIn('role_id', $roleIds);
        });
    }

    public function availableWarehouses()
    {
        if ($this->isAdmin()) {
            return Warehouse::query();
        }

        if ($this->warehouses()->exists()) {
            return $this->warehouses();
        }

        $roleIds = $this->roles()->pluck('id');
        return Warehouse::whereIn('id', function ($query) use ($roleIds) {
            $query->select('warehouse_id')
                  ->from('role_warehouses')
                  ->whereIn('role_id', $roleIds);
        });
    }

    public function availableAccounts()
    {
        if ($this->isAdmin()) {
            return Account::query();
        }

        if ($this->accounts()->exists()) {
            return $this->accounts();
        }

        $roleIds = $this->roles()->pluck('id');
        return Account::whereIn('id', function ($query) use ($roleIds) {
            $query->select('account_id')
                  ->from('role_accounts')
                  ->whereIn('role_id', $roleIds);
        });
    }
}
<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasActivityLog, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'client_group_id',
        'client_group_locked',
        'is_lead',
        'type',
        'name',
        'alias',
        'phone',
        'phone_2',
        'email',
        'source',
        'birth_date',
        'comment',
        'balance',
        'bonus_points',
        'requisites',
        'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'client_group_locked' => 'boolean',
            'discount_percent' => 'integer',
            'balance' => 'integer',
            'bonus_points' => 'integer',
            'birth_date' => 'date',
            'requisites' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'client_group_id');
    }

    /**
     * Роли клиента (Клиент/Подрядчик/Поставщик и свои) — многие-ко-многим
     * (client_roles) на справочник Lookup (type=client_role), одному клиенту
     * можно назначить сразу несколько одновременно. 3 базовые роли системные
     * (Lookup.is_system) — их нельзя переименовать/удалить в справочнике,
     * но можно продолжать заводить свои поверх них.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Lookup::class, 'client_roles')->withTimestamps();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}

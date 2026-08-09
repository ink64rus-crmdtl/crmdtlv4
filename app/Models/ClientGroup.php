<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientGroup extends Model
{
    protected $fillable = [
        'name',
        'color',
        'cashback_percent',
        'discount_percent',
        'min_turnover_amount',
        'min_orders_count',
        'auto_assign_period_days',
        'sort_order',
    ];

    protected $casts = [
        'cashback_percent' => 'float',
        'discount_percent' => 'float',
        'min_turnover_amount' => 'integer',
        'min_orders_count' => 'integer',
        'auto_assign_period_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Группа участвует в автоподборе (LoyaltyGradeService), только если задан
     * хотя бы один из порогов — иначе это чисто ручная группа.
     */
    public function hasAutoRule(): bool
    {
        return !is_null($this->min_turnover_amount) || !is_null($this->min_orders_count);
    }
}
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
    ];

    protected $casts = [
        'cashback_percent' => 'float',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegalEntity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'tax_id',
        'requisites',
        'is_active',
        'vat_payer',
        'vat_rate',
        'vat_calculation_method',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requisites' => 'array',
            'vat_payer' => 'boolean',
            'vat_rate' => 'integer',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Точки, от лица которых это юрлицо может выставлять документы —
     * многие-ко-многим (2027_01_28), привязка настраивается со стороны формы
     * юрлица (Settings/LegalEntities/Index.vue), см. LegalEntityController.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_legal_entity');
    }
}

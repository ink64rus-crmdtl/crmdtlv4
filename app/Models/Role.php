<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends SpatieRole
{
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'role_branches');
    }

    public function legalEntities(): BelongsToMany
    {
        return $this->belongsToMany(LegalEntity::class, 'role_legal_entities');
    }

    public function businessDirections(): BelongsToMany
    {
        return $this->belongsToMany(BusinessDirection::class, 'role_business_directions');
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'role_warehouses');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'role_accounts');
    }
}
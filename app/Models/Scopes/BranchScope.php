<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use App\Services\BranchContext;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($branchId = BranchContext::current()) {
            $builder->where($model->getTable() . '.branch_id', $branchId);
        }
    }
}
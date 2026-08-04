<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use App\Services\BranchContext;
use App\Services\LegalEntityContext;
use App\Services\UserScopeCachingService;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branchId = BranchContext::current();
        $legalEntityId = LegalEntityContext::current();

        if ($branchId) {
            // Если жестко выбран филиал, фильтруем только по нему
            $builder->where($model->getTable() . '.branch_id', $branchId);
        } elseif ($legalEntityId) {
            // Если выбран "Все филиалы", но выбрано конкретное Юрлицо,
            // отдаем записи по всем филиалам этого Юрлица, доступным пользователю
            if (auth()->check()) {
                $user = auth()->user();
                $availableBranches = $user->availableBranches()->where('legal_entity_id', $legalEntityId)->pluck('id')->toArray();
                
                $builder->whereIn($model->getTable() . '.branch_id', $availableBranches);
            }
        } else {
            // Если выбрано "Все Юрлица" и "Все Филиалы"
            // Отдаем записи по всем филиалам, доступным пользователю (ABAC)
            if (auth()->check() && !auth()->user()->isAdmin()) {
                $ids = UserScopeCachingService::getScopes(auth()->user(), 'branches');
                if (!in_array('*', $ids)) {
                    $builder->whereIn($model->getTable() . '.branch_id', $ids);
                }
            }
        }
    }
}
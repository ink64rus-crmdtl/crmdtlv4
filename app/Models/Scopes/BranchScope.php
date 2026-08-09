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
            // Если жестко выбрана точка, фильтруем только по ней
            $builder->where($model->getTable() . '.branch_id', $branchId);
        } elseif ($legalEntityId) {
            // Если выбраны "Все точки", но выбрано конкретное Юрлицо, отдаем
            // записи по всем точкам этого Юрлица, доступным пользователю —
            // точка теперь может иметь НЕСКОЛЬКО юрлиц (branch_legal_entity,
            // многие-ко-многим), поэтому через relation, не через колонку
            // (её на branches больше нет, см. миграцию 2027_01_28).
            if (auth()->check()) {
                $user = auth()->user();
                $availableBranches = $user->availableBranches()
                    ->whereHas('legalEntities', fn (Builder $q) => $q->where('legal_entities.id', $legalEntityId))
                    ->pluck('id')->toArray();

                $builder->whereIn($model->getTable() . '.branch_id', $availableBranches);
            }
        } else {
            // Если выбраны "Все Юрлица" и "Все Точки"
            // Отдаем записи по всем точкам, доступным пользователю (ABAC)
            if (auth()->check() && !auth()->user()->isAdmin()) {
                $ids = UserScopeCachingService::getScopes(auth()->user(), 'branches');
                if (!in_array('*', $ids)) {
                    $builder->whereIn($model->getTable() . '.branch_id', $ids);
                }
            }
        }
    }
}
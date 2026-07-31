<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\BranchContext;
use App\Services\LegalEntityContext;
use App\Models\Branch;

class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (tenancy()->initialized && $user) {
            // 1. Обработка Юридического лица
            $leId = session('current_legal_entity_id');
            $availableLEs = $user->availableLegalEntities()->where('is_active', true)->pluck('legal_entities.id')->toArray();

            if ($leId !== 'all') {
                if (!$leId || !in_array((int)$leId, $availableLEs)) {
                    // Если нет доступа к выбранному ЮЛ, сбрасываем на "Все" (или первое доступное)
                    $leId = 'all';
                    session(['current_legal_entity_id' => $leId]);
                }
            }
            LegalEntityContext::set($leId === 'all' ? null : (int)$leId);

            // 2. Обработка Филиала
            $branchId = session('current_branch_id');
            
            // Получаем список доступных ID филиалов для текущего пользователя (с учетом приоритетов)
            $availableBranchesQuery = $user->availableBranches()->where('is_active', true);
            
            // Если выбрано конкретное ЮЛ, ограничиваем филиалы им
            if ($leId !== 'all') {
                $availableBranchesQuery->where('legal_entity_id', (int)$leId);
            }
            
            $availableBranches = $availableBranchesQuery->pluck('branches.id')->toArray();

            if ($branchId !== 'all') {
                if (!$branchId || !in_array((int)$branchId, $availableBranches)) {
                    // Если выбранного филиала нет в доступных (или сменилось ЮЛ), сбрасываем на "Все"
                    $branchId = 'all';
                    session(['current_branch_id' => $branchId]);
                }
            }
            BranchContext::set($branchId === 'all' ? null : (int)$branchId);
        }

        return $next($request);
    }
}
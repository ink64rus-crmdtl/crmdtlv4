<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\BranchContext;
use App\Models\Branch;

class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (tenancy()->initialized && $user) {
            $branchId = session('current_branch_id');

            // Получаем список доступных ID филиалов для текущего пользователя (с учетом приоритетов User > Role)
            $availableBranches = $user->availableBranches()->where('is_active', true)->pluck('branches.id')->toArray();

            // Если филиал в сессии не выбран или недоступен пользователю, берем первый доступный
            if (!$branchId || !in_array($branchId, $availableBranches)) {
                $branchId = !empty($availableBranches) ? $availableBranches[0] : null;
                
                if ($branchId) {
                    session(['current_branch_id' => $branchId]);
                } else {
                    session()->forget('current_branch_id');
                }
            }

            // Устанавливаем глобальный контекст
            BranchContext::set($branchId);
        }

        return $next($request);
    }
}
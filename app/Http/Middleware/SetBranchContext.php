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
        if (tenancy()->initialized) {
            $branchId = session('current_branch_id');

            // Если филиал в сессии не выбран, берем первый активный
            if (!$branchId) {
                $firstBranch = Branch::where('is_active', true)->first();
                if ($firstBranch) {
                    $branchId = $firstBranch->id;
                    session(['current_branch_id' => $branchId]);
                }
            }

            // Устанавливаем глобальный контекст
            BranchContext::set($branchId);
        }

        return $next($request);
    }
}
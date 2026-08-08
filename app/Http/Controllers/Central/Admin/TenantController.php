<?php

namespace App\Http\Controllers\Central\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Список тенантов с базовыми показателями (Фаза 16, фундамент). Синхронный
 * обход через $tenant->run() нормален при текущем малом числе тенантов —
 * если тенантов станет много, потребуется вынести в кэш/очередь, но это
 * не предмет данной поставки.
 */
class TenantController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::with('domains')->orderBy('created_at', 'desc')->get()->map(function (Tenant $tenant) {
            $stats = $this->safeStats($tenant);

            return [
                'id' => $tenant->id,
                'domain' => $tenant->domains->first()?->domain,
                'country_code' => $tenant->country_code,
                'timezone' => $tenant->timezone,
                'created_at' => $tenant->created_at,
                'stats' => $stats,
            ];
        });

        return Inertia::render('Central/Admin/Tenants/Index', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Одна проблемная (например, ещё не смигрированная) БД тенанта не должна
     * ронять всю страницу списка — при ошибке просто показываем "нет данных".
     */
    private function safeStats(Tenant $tenant): ?array
    {
        try {
            return $tenant->run(function () {
                return [
                    'users' => DB::table('users')->count(),
                    'branches' => DB::table('branches')->count(),
                    'clients' => DB::table('clients')->count(),
                    'work_orders' => DB::table('work_orders')->count(),
                    'last_work_order_at' => DB::table('work_orders')->max('created_at'),
                ];
            });
        } catch (Throwable $e) {
            return null;
        }
    }
}

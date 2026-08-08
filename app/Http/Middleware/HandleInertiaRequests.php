<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Module;
use App\Models\Branch;
use App\Services\BranchContext;
use App\Services\LegalEntityContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // Право администратора на удаление без ограничений (CLAUDE.md, п. 6) —
                // фронту нужен явный флаг, а не расшифровка ролей на клиенте. Только в
                // tenant-контексте: central-гварды (напр. platform_admin, Фаза 16) не
                // имеют этого метода — тенантское понятие "право на удаление" им не
                // относится, там просто "залогинен = админ платформы".
                'isAdmin' => ($request->user() && tenancy()->initialized) ? $request->user()->isAdmin() : false,
            ],
            // Ленивая загрузка (Closure) гарантирует, что данные берутся ПОСЛЕ отработки SetBranchContext
            'modules' => fn () => ($request->user() && tenancy()->initialized)
                ? Module::where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->filter(function ($module) use ($request) {
                        // Админ видит все включенные модули
                        if ($request->user()->hasRole('admin')) {
                            return true;
                        }
                        // Если у модуля нет требования прав, либо пользователь имеет это право
                        if (empty($module->required_permission)) {
                            return true;
                        }
                        return $request->user()->can($module->required_permission);
                    })
                    ->map(function ($module) {
                        return [
                            'id' => $module->id,
                            'key' => $module->key,
                            'label' => $module->label,
                            'icon' => $module->icon,
                            'parent_id' => $module->parent_id,
                        ];
                    })
                    ->values()
                : [],
            'legal_entities' => fn () => ($request->user() && tenancy()->initialized)
                ? $request->user()->availableLegalEntities()->where('is_active', true)->get(['legal_entities.id', 'legal_entities.name'])
                : [],
            'branches' => fn () => ($request->user() && tenancy()->initialized)
                ? $request->user()->availableBranches()->where('is_active', true)->get(['branches.id', 'branches.name', 'branches.legal_entity_id'])
                : [],
            'current_legal_entity_id' => fn () => ($request->user() && tenancy()->initialized)
                ? LegalEntityContext::current() // null means 'all'
                : null,
            'current_branch_id' => fn () => ($request->user() && tenancy()->initialized)
                ? BranchContext::current() // null means 'all'
                : null,
            // Ссылка на кабинет администратора платформы (Фаза 16) — показывается
            // в тенантском сайдбаре только тенантским isAdmin-пользователям
            // (AppSidebar.vue). ВАЖНО: это лишь ярлык на страницу логина другого,
            // полностью отдельного контура (гвард platform_admin, central БД) —
            // сама по себе видимость ссылки прав не даёт, доступ по-прежнему
            // требует отдельных central-учётных данных. На сегодняшнем этапе
            // (единственный тенант — сам оператор платформы) это осознанное
            // упрощение; когда появятся независимые клиентские тенанты, их
            // администраторам показывать этот ярлык уже не будет иметь смысла —
            // потребуется более узкое условие видимости, чем общий isAdmin.
            'platformAdminUrl' => $this->platformAdminUrl($request),
        ];
    }

    private function platformAdminUrl(Request $request): string
    {
        $centralDomains = config('tenancy.central_domains', []);
        $domain = in_array('localhost', $centralDomains, true) ? 'localhost' : ($centralDomains[0] ?? 'localhost');
        $port = $request->getPort();
        $portSuffix = in_array($port, [80, 443], true) ? '' : ':' . $port;

        return $request->getScheme() . '://' . $domain . $portSuffix . '/admin/login';
    }
}
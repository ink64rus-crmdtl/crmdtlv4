<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Module;
use App\Models\Branch;
use App\Services\BranchContext;

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
            ],
            // Ленивая загрузка (Closure) гарантирует, что данные берутся ПОСЛЕ отработки SetBranchContext
            'modules' => fn () => ($request->user() && tenancy()->initialized)
                ? Module::where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(function ($module) {
                        return [
                            'id' => $module->id,
                            'key' => $module->key,
                            'label' => $module->label,
                            'icon' => $module->icon,
                            'parent_id' => $module->parent_id,
                        ];
                    })
                : [],
            'branches' => fn () => ($request->user() && tenancy()->initialized)
                ? Branch::where('is_active', true)->get(['id', 'name'])
                : [],
            'current_branch_id' => fn () => ($request->user() && tenancy()->initialized)
                ? BranchContext::current()
                : null,
        ];
    }
}
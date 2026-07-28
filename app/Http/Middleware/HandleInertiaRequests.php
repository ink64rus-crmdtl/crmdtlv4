<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Module;

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
        $modules = [];
        
        // Загружаем модули только если пользователь авторизован и мы находимся в тенанте
        if ($request->user() && tenancy()->initialized) {
            $modules = Module::where('is_enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($module) {
                    return [
                        'id' => $module->id,
                        'key' => $module->key,
                        'label' => $module->label, // Spatie вернет перевод для текущей локали
                        'icon' => $module->icon,
                        'parent_id' => $module->parent_id,
                    ];
                });
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'modules' => $modules,
        ];
    }
}
<?php

namespace App\Http\Middleware;

use App\Services\WarehouseResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гейт для веток склада, которые не имеют смысла при выключенном тумблере
 * складского учёта (Настройки → Склад): приходные накладные, остатки,
 * движения, задолженность поставщикам. Каталог товаров (warehouse.products.*)
 * и сама страница настроек склада (settings.warehouse.*) этим middleware
 * НЕ прикрыты — см. routes/tenant.php.
 */
class EnsureWarehouseEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! WarehouseResolver::isEnabled(), 403, 'Складской учёт отключён в настройках (Настройки → Склад).');

        return $next($request);
    }
}

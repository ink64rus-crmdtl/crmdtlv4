<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Pipeline;
use App\Services\Sales\PipelineAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Фаза 17, этап 4 — дашборд тенанта, ранее заглушка. Собирает аналитику
 * воронки продаж (единственная реальная аналитика в системе на этот момент,
 * см. CLAUDE.md Фаза 13 — остальная аналитика по клиентам/складу/ЗП пока
 * не реализована). Переключатель воронки — тот же паттерн resolvePipeline,
 * что и в DealController::index().
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $pipelines = Pipeline::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'is_default']);

        $pipeline = $this->resolvePipeline($request, $pipelines);

        if (! $pipeline) {
            return Inertia::render('Dashboard', [
                'pipelines' => $pipelines,
                'pipeline' => null,
            ]);
        }

        return Inertia::render('Dashboard', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline->only(['id', 'name']),
            'funnel' => PipelineAnalyticsService::funnel($pipeline),
            'forecast' => PipelineAnalyticsService::weightedForecast($pipeline->id),
            'closedStats' => PipelineAnalyticsService::closedStats($pipeline->id, 30),
            'bySource' => PipelineAnalyticsService::bySource($pipeline->id),
        ]);
    }

    private function resolvePipeline(Request $request, $pipelines): ?Pipeline
    {
        $requested = $request->input('pipeline_id');

        $id = $requested && $pipelines->contains('id', (int) $requested)
            ? (int) $requested
            : ($pipelines->firstWhere('is_default', true)?->id ?? $pipelines->first()?->id);

        return $id ? Pipeline::find($id) : null;
    }
}

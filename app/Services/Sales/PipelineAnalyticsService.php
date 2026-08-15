<?php

namespace App\Services\Sales;

use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Collection;

/**
 * Фаза 17, этап 4 — аналитика воронки. Тот же принцип, что и у
 * ClientSegmentService (Фаза 14.3): агрегаты СЧИТАЮТСЯ в SQL
 * (selectRaw/groupBy), а не через Deal::get() с подсчётом в PHP — на
 * растущей базе сделок последнее не масштабируется (CLAUDE.md §6).
 */
class PipelineAnalyticsService
{
    /**
     * Воронка по стадиям — СНЭПШОТ текущего распределения сделок, а не
     * когортная конверсия во времени: истории переходов между стадиями
     * система не хранит (только текстовые записи в ActivityLog, не
     * структурированные для агрегации). «Дошли до стадии X» здесь означает
     * «сейчас находятся на стадии X или дальше по воронке (включая «Успех»)»
     * — стандартное упрощение для CRM без выделенного event-лога стадий.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function funnel(Pipeline $pipeline): Collection
    {
        $stages = $pipeline->stages()->where('is_active', true)->get();

        $counts = Deal::where('pipeline_id', $pipeline->id)
            ->selectRaw('pipeline_stage_id, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as amt')
            ->groupBy('pipeline_stage_id')
            ->get()
            ->keyBy('pipeline_stage_id');

        $totalEntered = (int) $counts->sum('cnt');

        $openStages = $stages->where('type', PipelineStage::TYPE_OPEN)->sortBy('sort_order')->values();
        $wonStage = $stages->firstWhere('type', PipelineStage::TYPE_WON);

        // Суффиксные суммы: «на этой стадии или ЛЮБОЙ последующей открытой,
        // или в „Успехе“» — считаем с конца, чтобы каждая более ранняя
        // стадия унаследовала количество всех, что впереди неё по воронке.
        $orderedIds = $openStages->pluck('id')->push($wonStage?->id)->filter()->values();
        $suffixSum = 0;
        $reached = [];
        foreach ($orderedIds->reverse() as $id) {
            $suffixSum += (int) ($counts[$id]->cnt ?? 0);
            $reached[$id] = $suffixSum;
        }

        $rows = $openStages->map(fn (PipelineStage $stage) => [
            'stage' => ['id' => $stage->id, 'name' => $stage->name, 'color' => $stage->color, 'type' => $stage->type],
            'count' => (int) ($counts[$stage->id]->cnt ?? 0),
            'amount' => (int) ($counts[$stage->id]->amt ?? 0),
            'reached_percent' => $totalEntered > 0 ? (int) round(($reached[$stage->id] ?? 0) / $totalEntered * 100) : 0,
        ]);

        if ($wonStage) {
            $rows->push([
                'stage' => ['id' => $wonStage->id, 'name' => $wonStage->name, 'color' => $wonStage->color, 'type' => $wonStage->type],
                'count' => (int) ($counts[$wonStage->id]->cnt ?? 0),
                'amount' => (int) ($counts[$wonStage->id]->amt ?? 0),
                'reached_percent' => $totalEntered > 0 ? (int) round(($reached[$wonStage->id] ?? 0) / $totalEntered * 100) : 0,
            ]);
        }

        return $rows->values();
    }

    /**
     * Взвешенный прогноз — сумма всех ОТКРЫТЫХ сделок воронки и та же сумма
     * с учётом вероятности стадии каждой сделки (тот же расчёт, что и в
     * DealController::buildColumn() на доске, но по всей воронке разом).
     *
     * @return array{count: int, total: int, weighted: int}
     */
    public static function weightedForecast(int $pipelineId): array
    {
        $row = Deal::query()
            ->join('pipeline_stages', 'deals.pipeline_stage_id', '=', 'pipeline_stages.id')
            ->where('deals.pipeline_id', $pipelineId)
            ->where('pipeline_stages.type', PipelineStage::TYPE_OPEN)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.amount), 0) as total, COALESCE(SUM(deals.amount * pipeline_stages.probability / 100), 0) as weighted')
            ->first();

        return [
            'count' => (int) $row->cnt,
            'total' => (int) $row->total,
            'weighted' => (int) round((float) $row->weighted),
        ];
    }

    /**
     * Закрытые сделки (успех/проигрыш) за последние $days дней — источник
     * win rate и суммы выигранного для KPI-шапки дашборда.
     *
     * @return array{won_count: int, lost_count: int, won_amount: int, win_rate: ?int}
     */
    public static function closedStats(int $pipelineId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $rows = Deal::query()
            ->join('pipeline_stages', 'deals.pipeline_stage_id', '=', 'pipeline_stages.id')
            ->where('deals.pipeline_id', $pipelineId)
            ->whereIn('pipeline_stages.type', [PipelineStage::TYPE_WON, PipelineStage::TYPE_LOST])
            ->where('deals.closed_at', '>=', $since)
            ->selectRaw('pipeline_stages.type as stage_type, COUNT(*) as cnt, COALESCE(SUM(deals.amount), 0) as amt')
            ->groupBy('pipeline_stages.type')
            ->get()
            ->keyBy('stage_type');

        $won = (int) ($rows[PipelineStage::TYPE_WON]->cnt ?? 0);
        $lost = (int) ($rows[PipelineStage::TYPE_LOST]->cnt ?? 0);

        return [
            'won_count' => $won,
            'lost_count' => $lost,
            'won_amount' => (int) ($rows[PipelineStage::TYPE_WON]->amt ?? 0),
            'win_rate' => ($won + $lost) > 0 ? (int) round($won / ($won + $lost) * 100) : null,
        ];
    }

    /**
     * Разбивка ОТКРЫТЫХ сделок по источнику (Deal.source_lookup_id) —
     * «Не указан» для сделок без источника, а не молча исключается из отчёта:
     * само по себе большое число «не указан» — сигнал, что источник не заполняют.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function bySource(int $pipelineId): Collection
    {
        return Deal::query()
            ->leftJoin('lookups', 'deals.source_lookup_id', '=', 'lookups.id')
            ->where('deals.pipeline_id', $pipelineId)
            ->selectRaw("deals.source_lookup_id, COALESCE(lookups.label, 'Не указан') as label, COUNT(*) as cnt, COALESCE(SUM(deals.amount), 0) as amt")
            ->groupBy('deals.source_lookup_id', 'lookups.label')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'count' => (int) $row->cnt,
                'amount' => (int) $row->amt,
            ]);
    }
}

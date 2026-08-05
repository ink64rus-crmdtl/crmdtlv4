<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FinancePeriodClosure;
use App\Services\PeriodClosureService;
use App\Services\TimezoneResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

class PeriodClosureController extends Controller
{
    public function index(): Response
    {
        $closures = FinancePeriodClosure::with('closer')->orderByDesc('period_end_date')->get();

        return Inertia::render('Finance/PeriodClosure/Index', [
            'closures' => $closures,
            'closedThroughDate' => PeriodClosureService::closedThroughDate(),
            'suggestedPeriodEndDate' => $this->suggestedPeriodEndDate(),
            'canClose' => auth()->user()->isAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Закрывать финансовый период может только администратор.');
        }

        $validated = $request->validate([
            'period_end_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // before:today встроенного валидатора считает "today" по серверному (UTC) времени —
        // для закрытия периода нужна граница по времени тенанта.
        $tenantToday = Carbon::now(TimezoneResolver::forTenant())->toDateString();
        if ($validated['period_end_date'] >= $tenantToday) {
            return redirect()->back()->withErrors(['period_end_date' => 'Дата закрытия должна быть раньше сегодняшнего дня.']);
        }

        $closedThrough = PeriodClosureService::closedThroughDate();

        if ($closedThrough && Carbon::parse($validated['period_end_date'])->lte(Carbon::parse($closedThrough))) {
            return redirect()->back()->withErrors(['period_end_date' => "Период уже закрыт по {$closedThrough} — новая граница должна быть позже."]);
        }

        FinancePeriodClosure::create([
            'period_end_date' => $validated['period_end_date'],
            'closed_by' => auth()->id(),
            'closed_at' => now(),
            'note' => $validated['note'] ?? null,
        ]);

        return redirect()->back()->with('success', "Период закрыт по {$validated['period_end_date']}");
    }

    /**
     * Дата конца последнего уже завершившегося квартала — предлагается по умолчанию в форме,
     * но не навязывается: администратор может выбрать любую другую (более раннюю уже нельзя,
     * более позднюю — можно, если бизнесу удобнее закрывать помесячно).
     */
    private function suggestedPeriodEndDate(): ?string
    {
        // Закрытие периода — общетенантное действие (таблица без branch_id), поэтому "сегодня"
        // берем по часовому поясу тенанта, а не филиала.
        $today = Carbon::now(TimezoneResolver::forTenant())->startOfDay();
        $currentQuarterStartMonth = (intdiv($today->month - 1, 3)) * 3 + 1;
        $lastCompletedQuarterEnd = Carbon::create($today->year, $currentQuarterStartMonth, 1)->subDay();

        $closedThrough = PeriodClosureService::closedThroughDate();

        if ($closedThrough && Carbon::parse($closedThrough)->gte($lastCompletedQuarterEnd)) {
            return null;
        }

        return $lastCompletedQuarterEnd->toDateString();
    }
}

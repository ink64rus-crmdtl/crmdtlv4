<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ExportEntitiesJob;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\QueryFilterService;
use App\Services\TimezoneResolver;
use App\Services\WarehouseResolver;
use App\Services\WorkingHoursResolver;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $query = Branch::with('legalEntities');

        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name', 'city', 'address', 'phone'],
            allowedSorts: ['name', 'address', 'phone', 'is_active']
        );

        if (! request()->has('sort_by')) {
            $query->orderBy('id', 'desc');
        }

        $branchesList = $query->paginate(15)->withQueryString();
        $branchesList->getCollection()->each->append('logo_url');

        return Inertia::render('Settings/Branches/Index', [
            'branchesList' => $branchesList,
            'filters' => request()->all(),
            'timezones' => $this->timezones(),
            'tenantTimezone' => TimezoneResolver::forTenant(),
            'defaultWorkingHours' => WorkingHoursResolver::forTenant(),
        ]);
    }

    /**
     * Полный список часовых поясов IANA для выбора в форме локации.
     * Раньше в форме был хардкод из трёх поясов (Moscow/Berlin/Almaty) —
     * локация в любом другом городе оставалась без корректного времени.
     * Значения — авторитетный список PHP, не ручной перечень.
     */
    private function timezones(): array
    {
        $zones = DateTimeZone::listIdentifiers();
        sort($zones);

        $result = [];
        $now = new DateTimeImmutable('now');
        foreach ($zones as $zone) {
            $offsetSeconds = (new DateTimeZone($zone))->getOffset($now);
            $sign = $offsetSeconds >= 0 ? '+' : '-';
            $abs = abs($offsetSeconds);
            $hours = intdiv($abs, 3600);
            $minutes = intdiv($abs % 3600, 60);
            $offset = $minutes ? sprintf('%s%d:%02d', $sign, $hours, $minutes) : sprintf('%s%d', $sign, $hours);

            $result[] = [
                'value' => $zone,
                'label' => sprintf('%s (UTC%s)', $zone, $offset),
            ];
        }

        return $result;
    }

    /**
     * legal_entity_id тут больше не принимается — привязка точка↔юрлицо
     * настраивается со стороны формы юрлица (LegalEntityController), где
     * можно выбрать НЕСКОЛЬКО точек сразу (многие-ко-многим, см. миграцию
     * branch_legal_entity). Так привязка видна в одном месте, а не
     * расползается по двум формам с риском разойтись.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'working_hours' => ['nullable', 'array', 'size:7'],
            'working_hours.*.day' => ['required_with:working_hours', 'string'],
            'working_hours.*.is_open' => ['required_with:working_hours', 'boolean'],
            'working_hours.*.open' => ['nullable', 'string'],
            'working_hours.*.close' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ]);

        unset($validated['logo']);
        $branch = Branch::create($validated);

        if ($request->hasFile('logo')) {
            $branch->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        // Автосоздание склада точки (CLAUDE.md, "Настройка складов и режимов") —
        // самый частый в жизни сценарий дрейфа конфигурации: завели вторую локацию
        // в режиме per_branch/mixed, забыли завести под неё склад, и списание для
        // неё молча упало бы в null при первом же завершённом заказе. Только когда
        // это реально на что-то повлияет — при выключенном складе или в 'shared'
        // (там branch-склады resolveFor() вообще не смотрит) заводить его незачем.
        if (WarehouseResolver::isEnabled()) {
            $mode = Setting::where('key', 'warehouse_mode')->value('value') ?? 'per_branch';
            if (in_array($mode, ['per_branch', 'mixed'], true)) {
                Warehouse::create([
                    'name' => "Склад «{$branch->name}»",
                    'owner_type' => 'branch',
                    'owner_id' => $branch->id,
                    'is_default' => true,
                    'is_active' => true,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Локация успешно создана');
    }

    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'working_hours' => ['nullable', 'array', 'size:7'],
            'working_hours.*.day' => ['required_with:working_hours', 'string'],
            'working_hours.*.is_open' => ['required_with:working_hours', 'boolean'],
            'working_hours.*.open' => ['nullable', 'string'],
            'working_hours.*.close' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $removeLogo = ! empty($validated['remove_logo']);
        unset($validated['logo'], $validated['remove_logo']);
        $branch->update($validated);

        if ($request->hasFile('logo')) {
            $branch->addMediaFromRequest('logo')->toMediaCollection('logo');
        } elseif ($removeLogo) {
            $branch->clearMediaCollection('logo');
        }

        return redirect()->back()->with('success', 'Локация обновлена');
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();

        // Если удалили текущую точку, сбрасываем сессию на All
        if (session('current_branch_id') == $branch->id) {
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Локация удалена');
    }

    public function switch(Request $request, ?Branch $branch = null)
    {
        if ($branch && $branch->exists) {
            session(['current_branch_id' => $branch->id]);

            // Автоматически переключаем юрлицо на то, к которому принадлежит точка —
            // ТОЛЬКО если оно у неё ровно одно. Точка теперь может иметь несколько
            // юрлиц (branch_legal_entity) — в этом случае никакого умолчания нет,
            // сессия юрлица не трогается, выбор явно за пользователем.
            $legalEntityIds = $branch->legalEntities()->pluck('legal_entities.id');
            if ($legalEntityIds->count() === 1) {
                session(['current_legal_entity_id' => $legalEntityIds->first()]);
            }
        } else {
            session(['current_branch_id' => 'all']);
        }

        session()->save();

        return redirect()->back();
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:branches,id'],
        ]);

        Branch::whereIn('id', $validated['ids'])->delete();

        if (in_array(session('current_branch_id'), $validated['ids'])) {
            session(['current_branch_id' => 'all']);
        }

        return redirect()->back()->with('success', 'Выбранные локации удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:branches,id'],
        ]);

        ExportEntitiesJob::dispatch('branches', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен. Вы получите уведомление, когда файл будет готов.');
    }

    /**
     * Глобальные часы работы детейлинг-центра (раньше жили в CrmSettingsController
     * на вкладке «Клиенты и Авто»). Перенесено в «Локации», т.к. именно локации
     * наследуют это расписание по умолчанию (индивидуально — Branch::working_hours).
     */
    public function storeDefaultWorkingHours(Request $request)
    {
        $validated = $request->validate([
            'default_working_hours' => ['nullable', 'array', 'size:7'],
            'default_working_hours.*.day' => ['required_with:default_working_hours', 'string'],
            'default_working_hours.*.is_open' => ['required_with:default_working_hours', 'boolean'],
            'default_working_hours.*.open' => ['nullable', 'string'],
            'default_working_hours.*.close' => ['nullable', 'string'],
        ]);

        Setting::updateOrCreate(
            ['key' => 'default_working_hours'],
            ['value' => isset($validated['default_working_hours']) ? json_encode($validated['default_working_hours']) : null]
        );

        return redirect()->back()->with('success', 'Часы работы по умолчанию сохранены');
    }

    /**
     * Логотип отдаётся потоком, а не прямой ссылкой на disk — см. комментарий
     * у Branch::getLogoUrlAttribute() про несовместимость публичного disk-URL
     * с tenant-суффиксацией storage-путей. Кэш-заголовки — конверсия
     * перегенерируется только при повторной загрузке нового файла (singleFile
     * коллекция), поэтому агрессивное кэширование браузером безопасно.
     */
    public function logo(Branch $branch)
    {
        $media = $branch->getFirstMedia('logo');

        if (! $media) {
            abort(404);
        }

        return response()->file($media->getPath('thumb'), [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

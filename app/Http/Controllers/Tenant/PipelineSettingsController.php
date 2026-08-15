<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BusinessDirection;
use App\Models\Deal;
use App\Models\MessageTemplate;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\PipelineStageAutomation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройка воронок и их стадий (Фаза 17). Порядок стадий — drag-n-drop,
 * тем же паттерном, что уже работает у статусов заказ-нарядов
 * (LookupController::reorder + голая вёрстка таблицы, т.к. <DataTable>
 * под drag-n-drop не подходит — см. CLAUDE.md §7).
 */
class PipelineSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Pipelines/Index', [
            'pipelines' => Pipeline::with(['stages.automations.messageTemplate:id,name', 'businessDirection:id,name'])
                ->withCount('deals')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'businessDirections' => BusinessDirection::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stageTypes' => PipelineStage::TYPES,
            'automationActions' => PipelineStageAutomation::ACTIONS,
            'messageTemplates' => MessageTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_direction_id' => ['nullable', 'integer', 'exists:business_directions,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated) {
            $pipeline = Pipeline::create([
                'name' => $validated['name'],
                'business_direction_id' => $validated['business_direction_id'] ?? null,
                'is_default' => $validated['is_default'] ?? false,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => (int) Pipeline::max('sort_order') + 1,
            ]);

            $this->syncDefault($pipeline);

            // Новая воронка без стадий бесполезна — доску не из чего строить.
            // Заводим минимальный рабочий скелет, тенант правит под себя.
            foreach ([
                ['Новое обращение', 'gray', PipelineStage::TYPE_OPEN, 10],
                ['В работе', 'info', PipelineStage::TYPE_OPEN, 50],
                ['Успех', 'success', PipelineStage::TYPE_WON, 100],
                ['Проигрыш', 'danger', PipelineStage::TYPE_LOST, 0],
            ] as $index => [$name, $color, $type, $probability]) {
                $pipeline->stages()->create([
                    'name' => $name,
                    'color' => $color,
                    'type' => $type,
                    'probability' => $probability,
                    'sort_order' => $index,
                    'is_active' => true,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Воронка создана');
    }

    public function update(Request $request, Pipeline $pipeline)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_direction_id' => ['nullable', 'integer', 'exists:business_directions,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        DB::transaction(function () use ($pipeline, $validated) {
            $pipeline->update([
                'name' => $validated['name'],
                'business_direction_id' => $validated['business_direction_id'] ?? null,
                'is_default' => $validated['is_default'] ?? false,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncDefault($pipeline);
        });

        return redirect()->back()->with('success', 'Воронка обновлена');
    }

    public function destroy(Pipeline $pipeline)
    {
        // Защита целостности, а не workflow-статус — обхода для админа НЕТ
        // (CLAUDE.md §8, исключение из «права администратора на удаление»):
        // удаление воронки со сделками осиротило бы их pipeline_stage_id.
        if ($pipeline->deals()->exists()) {
            return redirect()->back()->withErrors(['error' => 'В воронке есть сделки — её нельзя удалить. Сначала перенесите или удалите сделки.']);
        }

        $pipeline->delete();

        return redirect()->back()->with('success', 'Воронка удалена');
    }

    public function storeStage(Request $request, Pipeline $pipeline)
    {
        $validated = $this->validateStage($request);

        $pipeline->stages()->create([
            ...$validated,
            'sort_order' => (int) $pipeline->stages()->max('sort_order') + 1,
        ]);

        return redirect()->back()->with('success', 'Стадия добавлена');
    }

    public function updateStage(Request $request, PipelineStage $stage)
    {
        $validated = $this->validateStage($request, $stage);

        $stage->update($validated);

        return redirect()->back()->with('success', 'Стадия обновлена');
    }

    public function destroyStage(PipelineStage $stage)
    {
        // Стадии закрытия неудаляемы в принципе: на них завязаны отчёты
        // воронки и автопереход в «Успех» при оплате связанного заказа.
        if ($stage->isClosing()) {
            return redirect()->back()->withErrors(['error' => 'Стадии «Успех» и «Проигрыш» удалить нельзя — на них завязаны отчёты и автопереход при оплате.']);
        }

        // Тот же запрет, что у статусов заказ-нарядов: не оставляем сделки
        // со ссылкой на несуществующую стадию.
        if (Deal::where('pipeline_stage_id', $stage->id)->exists()) {
            return redirect()->back()->withErrors(['error' => 'На этой стадии есть сделки — сначала перенесите их на другую стадию.']);
        }

        $stage->delete();

        return redirect()->back()->with('success', 'Стадия удалена');
    }

    public function reorderStages(Request $request, Pipeline $pipeline)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:pipeline_stages,id'],
        ]);

        DB::transaction(function () use ($validated, $pipeline) {
            foreach ($validated['ids'] as $index => $id) {
                PipelineStage::where('id', $id)
                    ->where('pipeline_id', $pipeline->id)
                    ->update(['sort_order' => $index]);
            }
        });

        return redirect()->back()->with('success', 'Порядок стадий обновлён');
    }

    /** Действия, автоматически выполняемые при входе сделки в эту стадию (этап 3). */
    public function storeAutomation(Request $request, PipelineStage $stage)
    {
        $validated = $this->validateAutomation($request);

        $stage->automations()->create($validated);

        return redirect()->back()->with('success', 'Автоматизация добавлена');
    }

    public function updateAutomation(Request $request, PipelineStageAutomation $automation)
    {
        $validated = $this->validateAutomation($request);

        $automation->update($validated);

        return redirect()->back()->with('success', 'Автоматизация обновлена');
    }

    public function destroyAutomation(PipelineStageAutomation $automation)
    {
        $automation->delete();

        return redirect()->back()->with('success', 'Автоматизация удалена');
    }

    // --- ВНУТРЕННЕЕ ---

    private function validateStage(Request $request, ?PipelineStage $stage = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', Rule::in(['gray', 'info', 'warning', 'success', 'danger', 'primary'])],
            'type' => ['required', 'string', Rule::in(array_keys(PipelineStage::TYPES))],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rotting_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['boolean'],
        ]);

        // Тип существующей стадии закрытия менять нельзя — иначе воронка
        // осталась бы без «Успеха», и автопереход при оплате перестал бы
        // находить целевую стадию, молча и без ошибки.
        if ($stage && $stage->isClosing() && $validated['type'] !== $stage->type) {
            throw ValidationException::withMessages([
                'type' => 'У стадий «Успех» и «Проигрыш» нельзя менять тип — на них завязаны отчёты и автопереход при оплате.',
            ]);
        }

        $validated['probability'] ??= 0;

        return $validated;
    }

    /** Флаг «по умолчанию» эксклюзивен — тот же паттерн, что у Account::is_default_for_invoicing. */
    private function syncDefault(Pipeline $pipeline): void
    {
        if ($pipeline->is_default) {
            Pipeline::where('id', '!=', $pipeline->id)->update(['is_default' => false]);
        }
    }

    private function validateAutomation(Request $request): array
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(PipelineStageAutomation::ACTIONS))],
            'message_template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'task_title' => ['nullable', 'string', 'max:255'],
            'task_due_offset_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
        ]);

        // send_message без шаблона отправлял бы пустое сообщение — бессмысленно
        // и незаметно для того, кто настраивал автоматизацию.
        if ($validated['action'] === PipelineStageAutomation::ACTION_SEND_MESSAGE && empty($validated['message_template_id'])) {
            throw ValidationException::withMessages([
                'message_template_id' => 'Для отправки сообщения нужно выбрать шаблон.',
            ]);
        }

        // Поля другого действия не должны молча оставаться от предыдущего
        // выбора в форме — иначе смена action на create_task оставила бы
        // призрачный message_template_id в записи.
        if ($validated['action'] !== PipelineStageAutomation::ACTION_SEND_MESSAGE) {
            $validated['message_template_id'] = null;
        }

        return $validated;
    }
}

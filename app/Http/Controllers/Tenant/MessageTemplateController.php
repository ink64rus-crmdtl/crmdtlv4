<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Фаза 11.1 — шаблоны сообщений и их триггеры. Набор триггеров сознательно
 * зашит списком (а не свободной строкой на фронте) — событие должно реально
 * где-то в коде вызывать MessageTemplateService, иначе шаблон молча никогда
 * не сработает. Актуальный список точек срабатывания см. в комментариях
 * WorkOrderController::updateStatus() (work_order.ready) и в команде
 * SendAppointmentReminders (appointment.reminder).
 */
class MessageTemplateController extends Controller
{
    public const TRIGGERS = [
        'work_order.ready' => 'Заказ готов к выдаче',
        'appointment.reminder' => 'Напоминание о записи',
    ];

    public function index(): Response
    {
        return Inertia::render('Settings/MessageTemplates/Index', [
            'templates' => MessageTemplate::orderBy('id', 'desc')->get(),
            'triggers' => self::TRIGGERS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTemplate($request);

        MessageTemplate::create([
            'name' => $validated['name'],
            'event_trigger' => $validated['event_trigger'] ?? null,
            'body' => [app()->getLocale() => $validated['body']],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Шаблон создан');
    }

    public function update(Request $request, MessageTemplate $messageTemplate)
    {
        $validated = $this->validateTemplate($request);

        $messageTemplate->setTranslation('body', app()->getLocale(), $validated['body']);
        $messageTemplate->update([
            'name' => $validated['name'],
            'event_trigger' => $validated['event_trigger'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $messageTemplate->save();

        return redirect()->back()->with('success', 'Шаблон обновлён');
    }

    public function destroy(MessageTemplate $messageTemplate)
    {
        $messageTemplate->delete();

        return redirect()->back()->with('success', 'Шаблон удалён');
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'event_trigger' => ['nullable', 'string', Rule::in(array_keys(self::TRIGGERS))],
            'body' => ['required', 'string', 'max:4096'],
            'is_active' => ['boolean'],
        ]);
    }
}

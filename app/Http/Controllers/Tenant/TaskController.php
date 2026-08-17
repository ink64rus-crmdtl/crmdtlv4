<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WorkingHoursResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Фаза 17, этап 2 — задачи. Task ОБЩЕСИСТЕМНАЯ и ПОЛИМОРФНАЯ (taskable
 * может быть Deal/Client/WorkOrder/Vehicle или отсутствовать), поэтому
 * этот контроллер не привязан к разделу «Продажи» логически — только
 * маршрутами лежит рядом с ним, т.к. это основной драйвер этапа
 * (сделка без следующего шага).
 */
class TaskController extends Controller
{
    public function index(Request $request)
    {
        $query = Task::with(['taskable', 'assignedTo:id,name']);

        // Встраиваемый виджет на Карточке сделки/клиента/заказа/авто —
        // тот же контроллер, без отдельного эндпоинта.
        $isEmbeddedPanel = $request->filled('taskable_type') && $request->filled('taskable_id');
        if ($isEmbeddedPanel) {
            // В БД taskable_type хранится полным FQCN (см. validateTask()) —
            // с фронта приходит короткое имя (Deal, Client...), их нужно
            // сопоставлять тем же способом, иначе панель всегда пуста.
            $query->where('taskable_type', 'App\\Models\\'.$request->input('taskable_type'))
                ->where('taskable_id', $request->input('taskable_id'));
        }

        // "Мои" по умолчанию — но только на самостоятельной странице "Мои
        // задачи". Панель на Карточке записи показывает ВСЕ задачи этой
        // записи независимо от того, кто назначен (там же задачу обычно
        // ставят без явного исполнителя) — иначе задача, поставленная без
        // назначения, пропадала бы из своей же панели.
        if (! $isEmbeddedPanel) {
            if ($request->input('mine', '1') !== '0') {
                $query->where('assigned_to_user_id', auth()->id());
            } elseif ($assignee = $request->input('assigned_to_user_id')) {
                $query->where('assigned_to_user_id', $assignee);
            }
        }

        match ($request->input('status', 'open')) {
            'open' => $query->whereNull('completed_at'),
            'overdue' => $query->whereNull('completed_at')->whereNotNull('due_at')->where('due_at', '<', now()),
            'completed' => $query->whereNotNull('completed_at'),
            default => null, // 'all' — без фильтра
        };

        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $query->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id');

        // AJAX-встраивание (панель на Карточке) — без Inertia-обёртки,
        // тот же приём, что и у ProductController::index() для SearchableSelect.
        if (($request->wantsJson() || $request->ajax()) && ! $request->hasHeader('X-Inertia')) {
            return response()->json(
                $query->get()->map(fn (Task $t) => $this->presentTask($t))
            );
        }

        $tasks = $query->paginate(20)->withQueryString();
        $tasks->getCollection()->transform(fn (Task $t) => $this->presentTask($t));

        return Inertia::render('Sales/Tasks/Index', [
            'tasks' => $tasks,
            'filters' => $request->only(['mine', 'status', 'assigned_to_user_id', 'search']),
            'taskTypes' => Lookup::where('type', 'task_type')->where('is_active', true)->orderBy('sort_order')->get(['id', 'value', 'label']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            // Задача общесистемная, без явного выбора локации в форме — время
            // выбирается в пределах рабочих часов ОРГАНИЗАЦИИ целиком, не точки.
            'defaultWorkingHours' => WorkingHoursResolver::forTenant(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateTask($request);

        $task = Task::create([
            ...$validated,
            'branch_id' => $validated['branch_id'] ?? auth()->user()->availableBranches()->value('branches.id'),
            'created_by' => auth()->id(),
        ]);

        if ($task->taskable) {
            ActivityLogger::log(
                $task->taskable,
                'Поставлена задача: «'.$task->title.'»'.($task->due_at ? ' (срок '.$task->due_at->format('d.m.Y H:i').')' : ''),
                [],
                'task_created'
            );
        }

        return redirect()->back()->with('success', 'Задача создана');
    }

    public function update(Request $request, Task $task)
    {
        $validated = $this->validateTask($request, $task);

        $task->update($validated);

        return redirect()->back()->with('success', 'Задача обновлена');
    }

    public function complete(Task $task)
    {
        $task->update(['completed_at' => now()]);

        if ($task->taskable) {
            ActivityLogger::log($task->taskable, 'Задача «'.$task->title.'» выполнена', [], 'task_completed');
        }

        return redirect()->back()->with('success', 'Задача выполнена');
    }

    public function reopen(Task $task)
    {
        $task->update(['completed_at' => null]);

        return redirect()->back()->with('success', 'Задача возвращена в работу');
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->back()->with('success', 'Задача удалена');
    }

    private function validateTask(Request $request, ?Task $task = null): array
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'taskable_type' => ['nullable', 'string', Rule::in(['Deal', 'Client', 'WorkOrder', 'Vehicle'])],
            'taskable_id' => ['nullable', 'integer', 'required_with:taskable_type'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);

        // taskable_type приходит с фронта коротким именем класса (Deal, Client...)
        // — превращаем в полный FQCN, как хранит nullableMorphs у остальных
        // полиморфных связей проекта (WorkOrderItem.itemable и т.п.).
        if (! empty($validated['taskable_type'])) {
            $validated['taskable_type'] = 'App\\Models\\'.$validated['taskable_type'];
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type,
            'due_at' => $task->due_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'is_overdue' => $task->isOverdue(),
            'assigned_to' => $task->assignedTo?->only(['id', 'name']),
            'taskable_type' => $task->taskable_type ? class_basename($task->taskable_type) : null,
            'taskable_id' => $task->taskable_id,
            'taskable_label' => $task->taskableLabel(),
        ];
    }
}

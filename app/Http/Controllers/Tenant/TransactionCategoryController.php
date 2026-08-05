<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TransactionCategory;
use App\Models\ListView;
use App\Services\QueryFilterService;
use App\Jobs\ExportEntitiesJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionCategoryController extends Controller
{
    public function index(): Response
    {
        $query = TransactionCategory::query();
        
        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name']
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('type')->orderBy('id', 'desc');
        }

        $categories = $query->paginate(15)->withQueryString();

        $availableColumns = [
            ['key' => 'type', 'label' => 'Тип'],
            ['key' => 'name', 'label' => 'Название статьи'],
            ['key' => 'status', 'label' => 'Статус'],
        ];

        $listView = ListView::where('entity_type', 'transaction_category')->where('user_id', auth()->id())->first();
        $visibleColumns = $listView ? $listView->visible_columns : array_column($availableColumns, 'key');

        return Inertia::render('Finance/Categories/Index', [
            'categories' => $categories,
            'filters' => request()->all(),
            'availableColumns' => $availableColumns,
            'listView' => ['visible_columns' => $visibleColumns],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:income,expense'],
            'is_active' => ['boolean'],
        ]);

        TransactionCategory::create([
            'name' => [app()->getLocale() => $validated['name']],
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Статья успешно создана');
    }

    public function update(Request $request, TransactionCategory $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:income,expense'],
            'is_active' => ['boolean'],
        ]);

        $name = $category->getTranslations('name');
        $name[app()->getLocale()] = $validated['name'];

        $category->update([
            'name' => $name,
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Статья обновлена');
    }

    public function destroy(TransactionCategory $category)
    {
        $category->delete();
        return redirect()->back()->with('success', 'Статья удалена');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:transaction_categories,id'],
        ]);

        TransactionCategory::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные статьи удалены');
    }

    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:transaction_categories,id'],
        ]);

        ExportEntitiesJob::dispatch('transaction_categories', $validated['ids'], auth()->id());

        return redirect()->back()->with('success', 'Экспорт запущен.');
    }
}
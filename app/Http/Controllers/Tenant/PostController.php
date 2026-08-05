<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Branch;
use App\Models\BusinessDirection;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function index(): Response
    {
        $query = Post::with(['branch', 'businessDirections']);

        $query = QueryFilterService::apply(
            $query,
            request()->all(),
            ['name']
        );

        if (!request()->has('sort_by')) {
            $query->orderBy('sort_order')->orderBy('id');
        }

        $posts = $query->paginate(15)->withQueryString();

        return Inertia::render('Settings/Posts/Index', [
            'posts' => $posts,
            'filters' => request()->all(),
            'branches' => Branch::forSelect()->get(['id', 'name']),
            'businessDirections' => BusinessDirection::where('is_active', true)->get(['id', 'name']),
            'hasBranches' => Branch::count() > 0,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePost($request);

        DB::transaction(function () use ($validated) {
            $nextSortOrder = (int) Post::max('sort_order') + 1;

            $post = Post::create([
                'branch_id' => $validated['branch_id'] ?? null,
                'name' => $validated['name'],
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $nextSortOrder,
            ]);

            if (!empty($validated['business_direction_ids'])) {
                $post->businessDirections()->sync($validated['business_direction_ids']);
            }
        });

        return redirect()->back()->with('success', 'Пост успешно создан');
    }

    public function update(Request $request, Post $post)
    {
        $validated = $this->validatePost($request);

        DB::transaction(function () use ($validated, $post) {
            $post->update([
                'branch_id' => $validated['branch_id'] ?? null,
                'name' => $validated['name'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $post->businessDirections()->sync($validated['business_direction_ids'] ?? []);
        });

        return redirect()->back()->with('success', 'Пост обновлён');
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return redirect()->back()->with('success', 'Пост удалён');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:posts,id'],
        ]);

        Post::whereIn('id', $validated['ids'])->delete();

        return redirect()->back()->with('success', 'Выбранные посты удалены');
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:posts,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['ids'] as $index => $id) {
                Post::where('id', $id)->update(['sort_order' => $index]);
            }
        });

        return redirect()->back()->with('success', 'Порядок постов обновлён');
    }

    private function validatePost(Request $request): array
    {
        $hasBranches = Branch::count() > 0;
        $branchRule = $hasBranches ? ['required', 'exists:branches,id'] : ['nullable', 'exists:branches,id'];

        return $request->validate([
            'branch_id' => $branchRule,
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'business_direction_ids' => ['nullable', 'array'],
            'business_direction_ids.*' => ['exists:business_directions,id'],
        ]);
    }
}

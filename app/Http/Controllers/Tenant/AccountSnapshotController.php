<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AccountDailySnapshot;
use App\Services\QueryFilterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountSnapshotController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AccountDailySnapshot::with('account');

        $query = QueryFilterService::apply($query, $request->all(), []);

        if (!$request->has('sort_by')) {
            $query->orderBy('snapshot_date', 'desc')->orderBy('account_id');
        }

        $snapshots = $query->paginate(31)->withQueryString();

        $accounts = auth()->user()->availableAccounts()->where('is_active', true)->get(['accounts.id', 'accounts.name', 'accounts.type']);

        return Inertia::render('Finance/Snapshots/Index', [
            'snapshots' => $snapshots,
            'accounts' => $accounts,
            'filters' => $request->all(),
        ]);
    }
}

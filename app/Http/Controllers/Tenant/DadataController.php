<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\DadataService;
use Illuminate\Http\Request;

/**
 * Общий прокси к DaData Suggestions API — не привязан к конкретной форме,
 * используется и Settings/LegalEntities/Index.vue (юрлицо тенанта), и
 * CRM/Clients/Index.vue (реквизиты B2B-клиента), см. CompanySuggestInput.vue.
 * Ключ DaData тенант не видит (см. DadataService).
 */
class DadataController extends Controller
{
    public function suggestParty(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if (! DadataService::isConfigured()) {
            return response()->json(['configured' => false, 'suggestions' => []]);
        }

        return response()->json([
            'configured' => true,
            'suggestions' => DadataService::suggestParty($validated['query']),
        ]);
    }
}

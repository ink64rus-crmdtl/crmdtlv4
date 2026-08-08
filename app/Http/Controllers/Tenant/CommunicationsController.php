<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Фаза 11.3 — единый инбокс внешней переписки с клиентами (список всех чатов
 * сразу, а не только из карточки одного клиента). Осознанно ограничено
 * external-перепиской — полноценный внутренний чат сотрудников вынесен в
 * отдельную будущую фазу вместе с PWA (см. CLAUDE.md, Фаза 15).
 *
 * Сам просмотр/отправка конкретной переписки НЕ дублирует ChatController —
 * фронтенд переиспользует ChatPanel.vue и его существующие роуты
 * (crm.clients.chats / crm.clients.chats.send) по client_id, полученному
 * из строки списка ниже. Здесь только сам список ("кто мне писал").
 */
class CommunicationsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Communications/Index', [
            'channels' => Channel::where('is_active', true)
                ->whereIn('provider', ['wappi_pro', 'green_api'])
                ->get(['id', 'name', 'messenger_type']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    public function chats(Request $request)
    {
        $query = Chat::where('type', 'external')
            ->whereNotNull('client_id')
            ->with([
                'client:id,name,phone',
                'channel:id,name,messenger_type',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json(['chats' => $query->limit(100)->get()]);
    }
}

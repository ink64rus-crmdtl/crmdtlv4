<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Channel;
use App\Services\Messaging\MessengerProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Exception;

/**
 * Settings → Каналы связи (Фаза 11.2). Управление подключёнными номерами/
 * ботами — CRUD + подключение (QR) для мессенджеров. Ничего не знает про
 * конкретного провайдера напрямую — только через MessengerProviderFactory/
 * SmsProviderFactory, поэтому переезд на другого провайдера не требует правок
 * этого контроллера.
 */
class ChannelController extends Controller
{
    private const MESSENGER_PROVIDERS = ['wappi_pro', 'green_api'];
    private const SMS_PROVIDERS = ['sms_aero'];

    public function index(): Response
    {
        return Inertia::render('Settings/Channels/Index', [
            'channels' => Channel::with('branch')->orderBy('id', 'desc')->get(),
            'branches' => Branch::forSelect()->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateChannel($request);

        $channel = Channel::create($validated);

        if (in_array($channel->provider, self::MESSENGER_PROVIDERS, true)) {
            try {
                MessengerProviderFactory::make($channel)->registerWebhook(
                    $channel,
                    route('webhooks.messenger', ['provider' => $channel->provider, 'webhookToken' => $channel->webhook_token])
                );
            } catch (Exception $e) {
                return redirect()->back()->with('warning', 'Канал создан, но не удалось зарегистрировать вебхук у провайдера: ' . $e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Канал добавлен');
    }

    public function update(Request $request, Channel $channel)
    {
        $validated = $this->validateChannel($request, $channel);

        $channel->update($validated);

        return redirect()->back()->with('success', 'Канал обновлён');
    }

    public function destroy(Channel $channel)
    {
        $channel->delete();

        return redirect()->back()->with('success', 'Канал удалён');
    }

    /**
     * QR для подключения номера — фронт открывает модалку и поллит этот
     * эндпоинт вместе со status(), пока канал не станет connected.
     */
    public function qrCode(Channel $channel)
    {
        if (!in_array($channel->provider, self::MESSENGER_PROVIDERS, true)) {
            return response()->json(['error' => 'У этого провайдера нет подключения по QR'], 422);
        }

        $qr = MessengerProviderFactory::make($channel)->getQrCode($channel);

        return response()->json(['qr' => $qr]);
    }

    public function status(Channel $channel)
    {
        if (!in_array($channel->provider, self::MESSENGER_PROVIDERS, true)) {
            return response()->json(['status' => $channel->status]);
        }

        $status = MessengerProviderFactory::make($channel)->getConnectionStatus($channel);

        return response()->json(['status' => $status]);
    }

    /**
     * $channel === null → создание нового канала (учётные данные обязательны).
     * $channel передан → редактирование: пустые поля credentials в форме
     * означают "не менять" (фронт не подставляет реальный токен обратно в
     * форму из соображений безопасности) — иначе правка одного только имени
     * канала молча стирала бы уже рабочий токен/API-ключ.
     */
    private function validateChannel(Request $request, ?Channel $channel = null): array
    {
        $isCreating = $channel === null;
        $provider = $request->input('provider');

        $rules = [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in([...self::MESSENGER_PROVIDERS, ...self::SMS_PROVIDERS])],
            'messenger_type' => ['required', 'string', 'in:whatsapp,telegram,max,sms'],
            'external_profile_id' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
            'credentials' => ['required', 'array'],
        ];

        if ($isCreating) {
            $rules['external_profile_id'][] = Rule::requiredIf(in_array($provider, self::MESSENGER_PROVIDERS, true));
            $rules['credentials.token'] = [Rule::requiredIf(in_array($provider, self::MESSENGER_PROVIDERS, true)), 'string'];
            $rules['credentials.email'] = [Rule::requiredIf($provider === 'sms_aero'), 'string'];
            $rules['credentials.api_key'] = [Rule::requiredIf($provider === 'sms_aero'), 'string'];
        }

        $validated = $request->validate($rules);

        $allowedMessengerTypes = match ($validated['provider']) {
            'wappi_pro', 'green_api' => ['whatsapp', 'telegram', 'max'],
            'sms_aero' => ['sms'],
            default => [],
        };

        if (!in_array($validated['messenger_type'], $allowedMessengerTypes, true)) {
            throw ValidationException::withMessages([
                'messenger_type' => 'Этот тип канала недоступен для выбранного провайдера.',
            ]);
        }

        if (!$isCreating) {
            $validated['credentials'] = $this->mergeCredentials($channel->credentials ?? [], $validated['credentials']);
        }

        return $validated;
    }

    /**
     * Пустое поле (пользователь ничего не ввёл в него) — сохраняем прежнее
     * значение по этому конкретному ключу; непустое — заменяем. Позволяет
     * поменять, например, только токен, не трогая остальные учётные данные.
     */
    private function mergeCredentials(array $existing, array $submitted): array
    {
        $merged = $existing;

        foreach ($submitted as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $merged[$key] = $value;
            } elseif (!is_string($value) && $value !== null) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}

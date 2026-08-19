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
 *
 * Тенант НЕ имеет доступа к личному кабинету Wappi.Pro (корпоративный
 * аккаунт, общий на всю платформу) — поэтому для messenger-провайдеров
 * (wappi_pro/green_api) тенант не вводит ни profile_id, ни токен: профиль
 * система создаёт сама (см. attemptProvisioning()), именуя его
 * "{tenant_id}-{channel_id}", чтобы администратор платформы мог опознать
 * владельца прямо в личном кабинете Wappi. См. CLAUDE.md, Фаза 16.
 */
class ChannelController extends Controller
{
    // ⚠️ green_api — ЗАДЕКЛАРИРОВАННЫЙ, но НЕ РЕАЛИЗОВАННЫЙ провайдер
    // (решение владельца: будет подключён позже). Он проходит валидацию
    // здесь, но ветки в MessengerProviderFactory::make() не имеет — попытка
    // провижининга такого канала молча упадёт (InvalidArgumentException →
    // статус «Ошибка настройки» навсегда), в UI он не выводится. НЕ удаляй
    // его из этого списка и НЕ добавляй ветку в фабрику раньше времени —
    // только вместе с реальной реализацией Green API (см. AGENTS.md §4).
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
            if (!$this->attemptProvisioning($channel)) {
                return redirect()->back()->with('warning', 'Канал добавлен, но не удалось создать профиль у провайдера. Повторите попытку кнопкой «Повторить» в списке каналов.');
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
        if (in_array($channel->provider, self::MESSENGER_PROVIDERS, true) && $channel->external_profile_id) {
            try {
                MessengerProviderFactory::make($channel)->releaseProfile($channel);
            } catch (Exception $e) {
                // best-effort — локальное удаление всё равно должно пройти,
                // профиль на стороне провайдера в худшем случае просто
                // останется висеть неиспользуемым.
            }
        }

        $channel->delete();

        return redirect()->back()->with('success', 'Канал удалён');
    }

    /**
     * Повторная попытка создать профиль у провайдера — для каналов, у
     * которых attemptProvisioning() упал при создании (external_profile_id
     * всё ещё null). Дедуп по имени внутри provisionProfile() корректно
     * подхватит уже созданный на стороне Wappi профиль, если тот успел
     * создаться до сбоя (см. WappiProProvider::findProfileIdByName()).
     */
    public function retryProvision(Channel $channel)
    {
        if (!in_array($channel->provider, self::MESSENGER_PROVIDERS, true)) {
            return redirect()->back()->withErrors(['error' => 'У этого провайдера нет автоматического подключения.']);
        }

        if (!$this->attemptProvisioning($channel)) {
            return redirect()->back()->withErrors(['error' => 'Не удалось создать профиль у провайдера. Попробуйте ещё раз позже.']);
        }

        return redirect()->back()->with('success', 'Профиль успешно создан — можно сканировать QR');
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

        if (!$channel->external_profile_id) {
            return response()->json(['error' => 'Профиль ещё не создан у провайдера'], 422);
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
     * Создаёт (или переиспользует по имени) профиль у провайдера и
     * сохраняет external_profile_id. Не бросает исключение наружу —
     * вызывающий код (store()/retryProvision()) сам решает, как сообщить
     * об ошибке; канал при неудаче остаётся в БД без profile_id, чтобы
     * можно было повторить попытку, не плодя новых Channel-записей (а
     * значит — и новых имён профилей у провайдера).
     */
    private function attemptProvisioning(Channel $channel): bool
    {
        try {
            $profileId = MessengerProviderFactory::make($channel)->provisionProfile(
                $channel,
                tenant('id') . '-' . $channel->id,
                route('webhooks.messenger', ['provider' => $channel->provider, 'webhookToken' => $channel->webhook_token])
            );

            $channel->update(['external_profile_id' => $profileId]);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * $channel === null → создание нового канала. $channel передан →
     * редактирование: пустые поля credentials в форме означают "не менять"
     * (фронт не подставляет реальный секрет обратно из соображений
     * безопасности) — иначе правка одного только имени канала молча стирала
     * бы уже рабочие учётные данные (актуально только для SMS Aero —
     * messenger-провайдеры вообще не используют credentials, см. выше).
     */
    private function validateChannel(Request $request, ?Channel $channel = null): array
    {
        $isCreating = $channel === null;
        $provider = $request->input('provider');
        $isMessenger = in_array($provider, self::MESSENGER_PROVIDERS, true);

        $rules = [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in([...self::MESSENGER_PROVIDERS, ...self::SMS_PROVIDERS])],
            'messenger_type' => ['required', 'string', 'in:whatsapp,telegram,max,sms'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
            'credentials' => ['nullable', 'array'],
        ];

        // external_profile_id и credentials.token для messenger-провайдеров
        // тенант больше не вводит — профиль создаёт сама система
        // (attemptProvisioning()), токен общий на платформу (PlatformSetting).
        if ($isCreating && !$isMessenger) {
            $rules['credentials.email'] = [Rule::requiredIf($provider === 'sms_aero'), 'string'];
            $rules['credentials.api_key'] = [Rule::requiredIf($provider === 'sms_aero'), 'string'];
        }

        $validated = $request->validate($rules);
        $validated['credentials'] = $validated['credentials'] ?? [];

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

        if (!$isCreating && !$isMessenger) {
            $validated['credentials'] = $this->mergeCredentials($channel->credentials ?? [], $validated['credentials']);
        }

        return $validated;
    }

    /**
     * Пустое поле (пользователь ничего не ввёл в него) — сохраняем прежнее
     * значение по этому конкретному ключу; непустое — заменяем. Позволяет
     * поменять, например, только токен, не трогая остальные учётные данные.
     * Актуально только для SMS Aero — у messenger-провайдеров credentials
     * больше не используются вовсе.
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

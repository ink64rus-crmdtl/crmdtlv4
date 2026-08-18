// Ленивый синглтон Echo. Reverb подключается НЕ на загрузку страницы (как было
// в bootstrap.js), а при первом реальном использовании чата. Иначе консоль
// заваливается ошибками WebSocket на страницах, где чат не нужен (лендинг,
// страница регистрации тенанта) — особенно когда Reverb-сервер не запущен.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echo = null;

export function getEcho() {
    if (!echo) {
        // /broadcasting/auth резолвится в контексте текущего тенанта (см.
        // routes/tenant.php — зарегистрирован внутри tenancy+auth группы),
        // поэтому Echo просто стучится на тот же домен, на котором открыта
        // страница — переносить между тенантами ничего не нужно.
        echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return echo;
}

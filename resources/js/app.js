import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { createI18n } from 'vue-i18n';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Минимальная обвязка (CLAUDE.md §6): единственная локаль 'ru', каталог
// переводов ПУСТОЙ намеренно — ключ и есть русский текст ({{ $t('Добавить
// клиента') }}), vue-i18n при отсутствии перевода в messages сам возвращает
// сам ключ как есть. Задача этого шага — только чтобы $t() работал и не
// падал в новом коде, не переводить существующие ~100 файлов (это отдельная,
// сознательно отложенная задача). missingWarn/fallbackWarn выключены, т.к.
// "отсутствующий" перевод — здесь ожидаемое обычное состояние КАЖДОГО
// ключа, а не сигнал реальной проблемы.
const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: 'ru',
    fallbackLocale: 'ru',
    messages: { ru: {} },
    missingWarn: false,
    fallbackWarn: false,
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Вкладка могла быть открыта до пересборки фронтенда (npm run build меняет
// хэши файлов чанков и удаляет старые) — тогда переход на страницу, чей чанк
// с тех пор пересобрался, молча проваливается (динамический import 404),
// без видимой ошибки: клик по пункту сайдбара просто ничего не делает. Vite
// в такой ситуации сам бросает событие vite:preloadError — ловим и перезагружаем
// страницу один раз, чтобы получить актуальный бандл, вместо того чтобы
// заставлять пользователя самого догадываться сделать Ctrl+Shift+R.
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    const key = 'vitePreloadErrorReloadedAt';
    const last = Number(sessionStorage.getItem(key) || 0);
    if (Date.now() - last > 10000) {
        sessionStorage.setItem(key, String(Date.now()));
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

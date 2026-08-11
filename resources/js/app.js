import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

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
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

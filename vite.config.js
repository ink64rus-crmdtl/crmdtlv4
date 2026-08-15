import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // vue-i18n@11 ломается через оптимизатор зависимостей Rolldown-Vite (v8):
    // "ReferenceError: init_runtime_dom_esm_bundler is not defined" — оптимизатор
    // некорректно разносит внутренний shared-хелпер пакета по чанкам при
    // пред-бандлинге. exclude заставляет отдавать оригинальный ESM-файл пакета
    // как есть, без прогона через оптимизатор — обходит именно этот баг чанкинга.
    optimizeDeps: {
        exclude: ['vue-i18n'],
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});

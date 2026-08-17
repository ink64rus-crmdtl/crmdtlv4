<script setup>
import Modal from '@/Components/Modal.vue';

// Предпросмотр шаблона документа (библиотека платформы — central-админка и
// тенантский импорт, CLAUDE.md "Предпросмотр библиотеки шаблонов документов")
// — рендер тела шаблона БЕЗ реальной записи, поэтому суммы/условные блоки в
// нём не соответствуют реальному документу, только вёрстка/шрифт/разметка.
// HTML показывается в изолированном <iframe sandbox> — не v-html в DOM
// страницы: тело шаблона редактируется пользователем (тенантским админом
// или платформенным), доверять ему как безопасному HTML не стоит, тот же
// принцип, что и у DocumentRenderer вместо Blade::render() (см. CLAUDE.md).
defineProps({
    show: Boolean,
    title: { type: String, default: 'Предпросмотр шаблона' },
    html: { type: String, default: '' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const close = () => emit('close');
</script>

<template>
    <Modal :show="show" @close="close" max-width="4xl">
        <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <div>
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ title }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Без реальных данных — плейсхолдеры пустые, условные блоки скрыты. Только вёрстка.</p>
                </div>
                <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
            </div>
            <div class="p-4">
                <div v-if="loading" class="flex items-center justify-center h-[70vh] text-sm text-gray-400">
                    <i class="ri-loader-4-line animate-spin text-xl mr-2"></i> Загрузка предпросмотра...
                </div>
                <iframe
                    v-else
                    sandbox=""
                    :srcdoc="html"
                    class="w-full h-[70vh] border border-gray-200 dark:border-gray-700 rounded-md bg-white"
                    title="Предпросмотр шаблона"
                ></iframe>
            </div>
        </div>
    </Modal>
</template>

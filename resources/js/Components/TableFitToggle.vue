<script setup>
import { ref, watch } from 'vue';

/**
 * Переключатель "резинового" вида таблицы (все колонки в ширину экрана,
 * длинный текст переносится) и обычного вида (автоширина колонок +
 * горизонтальная прокрутка). Состояние хранится в localStorage по ключу
 * storageKey — у каждой страницы свой, чтобы выбор не "протекал" между
 * разделами. Управляет пропом fitColumns у <DataTable>.
 */
const props = defineProps({
    storageKey: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const fit = ref(localStorage.getItem(props.storageKey) === '1');

watch(fit, (value) => {
    localStorage.setItem(props.storageKey, value ? '1' : '0');
    emit('update:modelValue', value);
});
</script>

<template>
    <button
        type="button"
        @click="fit = !fit"
        class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
        :title="fit ? 'Вместить все колонки в экран (перенос текста)' : 'Обычный вид: автоширина колонок и горизонтальная прокрутка'"
    >
        <i :class="[fit ? 'ri-fullscreen-exit-line' : 'ri-fullscreen-line', 'text-gray-500 dark:text-gray-400']"></i>
        <span class="hidden sm:inline">{{ fit ? 'Сжать колонки' : 'Вместить в экран' }}</span>
    </button>
</template>

<script setup>
import { useTableFit } from '@/Composables/useTableFit.js';

/**
 * Переключатель "резинового" вида таблицы (все колонки в ширину экрана, текст
 * переносится, название/наименование — вдвое шире остальных) и обычного вида
 * (автоширина колонок + горизонтальная прокрутка). Состояние — через общий стор
 * useTableFit() (localStorage по storageKey), его же читает DataTable той же
 * страницы, поэтому кнопка и таблица всегда синхронны.
 */
const props = defineProps({
    storageKey: {
        type: String,
        default: null,
    },
});

const { fit, setFit } = useTableFit(props.storageKey);
</script>

<template>
    <button
        type="button"
        @click="setFit(!fit)"
        class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
        :title="fit
            ? 'Вместить в экран: ВКЛ. Все колонки делят ширину страницы, длинный текст переносится. Колонка с названием/наименованием — в 2 раза шире остальных. Ширину любой колонки можно изменить перетаскиванием границы в заголовке, двойной клик по границе сбросит её. Нажмите, чтобы вернуть обычный вид (автоширина + горизонтальная прокрутка).'
            : 'Вместить в экран: ВЫКЛ (обычный вид — колонки по содержимому, при нехватке места — горизонтальная прокрутка). Нажмите, чтобы все колонки делили ширину страницы и текст переносился; колонка с названием/наименованием — в 2 раза шире остальных. Ширину любой колонки можно изменить перетаскиванием границы в заголовке, двойной клик по границе сбросит её.'"
    >
        <i :class="[fit ? 'ri-fullscreen-exit-line' : 'ri-fullscreen-line', 'text-gray-500 dark:text-gray-400']"></i>
        <span class="hidden sm:inline">{{ fit ? 'Сжать колонки' : 'Вместить в экран' }}</span>
    </button>
</template>

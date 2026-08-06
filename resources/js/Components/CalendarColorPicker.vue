<script setup>
/**
 * Выбор цвета записи в календаре — используется в карточке Сотрудника и в
 * карточке Поста (см. App\Support\CalendarPalette::COLORS, список должен
 * совпадать с валидацией на бэкенде).
 */
const props = defineProps({
    modelValue: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const PALETTE = [
    '#3e60d5', '#47ad77', '#f15776', '#ffc35a', '#16a7e9', '#6c757d',
    '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#84cc16', '#64748b',
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <button
            v-for="color in PALETTE"
            :key="color"
            type="button"
            @click="emit('update:modelValue', color)"
            :style="{ backgroundColor: color }"
            :class="modelValue === color ? 'ring-2 ring-offset-2 ring-gray-400 dark:ring-offset-gray-800' : ''"
            class="h-7 w-7 rounded-full shadow-sm transition-transform hover:scale-110"
            :title="color"
        ></button>
        <button
            type="button"
            @click="emit('update:modelValue', null)"
            :class="!modelValue ? 'ring-2 ring-offset-2 ring-gray-400 dark:ring-offset-gray-800' : ''"
            class="h-7 w-7 rounded-full border border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            title="Без своего цвета — цвет по статусу записи"
        ><i class="ri-close-line text-sm"></i></button>
    </div>
</template>

<script setup>
/**
 * Выбор иконки поста, отображается в календаре рядом с названием поста
 * (см. App\Support\CalendarPalette::POST_ICONS, список должен совпадать
 * с валидацией на бэкенде).
 */
const props = defineProps({
    modelValue: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const ICONS = [
    'ri-car-line',
    'ri-car-washing-line',
    'ri-drop-line',
    'ri-sparkling-2-line',
    'ri-shield-check-line',
    'ri-paint-brush-line',
    'ri-tools-line',
    'ri-sun-line',
    'ri-contrast-2-line',
    'ri-parking-box-line',
];
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="icon in ICONS"
            :key="icon"
            type="button"
            @click="emit('update:modelValue', icon)"
            :class="modelValue === icon ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 border-gray-200 dark:border-gray-700 hover:border-primary'"
            class="h-9 w-9 rounded-md border flex items-center justify-center transition-colors"
            :title="icon"
        ><i :class="icon" class="text-base"></i></button>
        <button
            type="button"
            @click="emit('update:modelValue', null)"
            :class="!modelValue ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-gray-800 text-gray-400 border-gray-200 dark:border-gray-700 hover:border-primary'"
            class="h-9 w-9 rounded-md border flex items-center justify-center transition-colors"
            title="Без иконки"
        ><i class="ri-close-line text-base"></i></button>
    </div>
</template>

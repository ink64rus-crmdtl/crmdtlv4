<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    // Один и тот же ключ для колонки на всех карточках (заказ/клиент/авто/сотрудник) —
    // свёрнутость запоминается глобально, а не отдельно на каждой странице.
    storageKey: {
        type: String,
        required: true,
    },
    side: {
        type: String,
        default: 'left', // 'left' | 'right' — определяет положение кнопки и направление стрелки
    },
});

const STORAGE_PREFIX = 'panel-collapsed:';

const isCollapsed = ref(localStorage.getItem(STORAGE_PREFIX + props.storageKey) === '1');
const isHoverPreview = ref(false);

watch(isCollapsed, (value) => {
    localStorage.setItem(STORAGE_PREFIX + props.storageKey, value ? '1' : '0');
});

const onMouseEnter = () => {
    if (isCollapsed.value) {
        isHoverPreview.value = true;
    }
};

const onMouseLeave = () => {
    isHoverPreview.value = false;
};

const toggle = () => {
    isCollapsed.value = !isCollapsed.value;
};

const iconFor = (collapsed) => {
    if (props.side === 'left') {
        return collapsed ? 'ri-arrow-right-s-line' : 'ri-arrow-left-s-line';
    }
    return collapsed ? 'ri-arrow-left-s-line' : 'ri-arrow-right-s-line';
};
</script>

<template>
    <div
        :class="isCollapsed ? 'lg:w-10' : 'lg:w-1/4'"
        class="w-full flex-shrink-0 transition-[width] duration-200 relative"
        @mouseenter="onMouseEnter"
        @mouseleave="onMouseLeave"
    >
        <!-- Переключатель — только на десктопе: на мобильном колонки и так в столбик на всю ширину,
             сворачивать там нечего (нет соседней колонки, отвоёвывающей место). -->
        <button
            type="button"
            @click="toggle"
            :title="isCollapsed ? 'Развернуть панель (наведите, чтобы посмотреть без разворачивания)' : 'Свернуть панель'"
            :class="side === 'left' ? '-right-3' : '-left-3'"
            class="hidden lg:flex items-center justify-center w-6 h-6 rounded-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-400 hover:text-primary hover:border-primary shadow-sm absolute top-1 z-40 transition-colors"
        >
            <i :class="iconFor(isCollapsed)" class="text-base"></i>
        </button>

        <!-- Полоса-плейсхолдер свёрнутой колонки (только десктоп) -->
        <div v-if="isCollapsed" class="hidden lg:flex flex-col items-center pt-10">
            <i class="ri-more-2-fill text-gray-300 dark:text-gray-600 text-lg"></i>
        </div>

        <!-- Предпросмотр поверх страницы при наведении на свёрнутую панель — не меняет
             раскладку (ширина колонки остаётся lg:w-10), просто всплывает сверху. Клик по
             стрелке по-прежнему разворачивает панель насовсем (меняет ширину колонки). -->
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isCollapsed && isHoverPreview"
                :class="side === 'left' ? 'left-0' : 'right-0'"
                class="hidden lg:block absolute top-0 w-80 max-w-[90vw] space-y-6 z-30"
            >
                <slot />
            </div>
        </Transition>

        <!-- Контент: на мобильном виден всегда, на десктопе скрывается при сворачивании -->
        <div :class="isCollapsed ? 'lg:hidden' : ''" class="space-y-6">
            <slot />
        </div>
    </div>
</template>

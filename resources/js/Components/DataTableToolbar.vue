<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    hasFilters: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: 'Поиск...',
    }
});

const emit = defineEmits(['update:modelValue', 'open-filters', 'open-columns']);

const searchQuery = ref(props.modelValue);

// Синхронизация локального стейта с v-model
watch(() => props.modelValue, (newVal) => {
    searchQuery.value = newVal;
});

const updateSearch = (e) => {
    searchQuery.value = e.target.value;
    emit('update:modelValue', searchQuery.value);
};

const clearSearch = () => {
    searchQuery.value = '';
    emit('update:modelValue', '');
};
</script>

<template>
    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex flex-col sm:flex-row justify-between items-center gap-4">
        
        <!-- Левая часть: Поиск -->
        <div class="relative w-full sm:w-72 shrink-0">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="ri-search-line text-gray-400"></i>
            </div>
            <input 
                type="text" 
                :value="searchQuery"
                @input="updateSearch"
                :placeholder="placeholder"
                class="block w-full pl-10 pr-10 py-2 border border-gray-200 dark:border-gray-700 rounded-md leading-5 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary sm:text-sm transition-colors"
            >
            <button 
                v-if="searchQuery" 
                @click="clearSearch"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
                <i class="ri-close-line"></i>
            </button>
        </div>

        <!-- Правая часть: Действия и Настройки -->
        <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
            
            <!-- Слот для кастомных кнопок (например, "Добавить") -->
            <slot name="actions"></slot>

            <div class="h-6 w-px bg-gray-200 dark:bg-gray-700 mx-1 hidden sm:block"></div>

            <!-- Кнопка Фильтров -->
            <button 
                @click="$emit('open-filters')" 
                class="relative inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
            >
                <i class="ri-filter-3-line text-gray-500 dark:text-gray-400"></i>
                <span class="hidden sm:inline">Фильтры</span>
                <!-- Индикатор активных фильтров -->
                <span v-if="hasFilters" class="absolute -top-1 -right-1 flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-primary border-2 border-white dark:border-gray-800"></span>
                </span>
            </button>

            <!-- Кнопка Настройки столбцов -->
            <button 
                @click="$emit('open-columns')" 
                class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
                title="Настроить столбцы"
            >
                <i class="ri-layout-column-line text-gray-500 dark:text-gray-400"></i>
            </button>
        </div>
    </div>
</template>
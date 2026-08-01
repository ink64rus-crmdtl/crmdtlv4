<script setup>
import { ref } from 'vue';

defineProps({
    title: {
        type: String,
        default: 'Информация',
    },
});

const isExpanded = ref(true);
</script>

<template>
    <div class="bg-info/10 border border-info/20 rounded-md p-4 flex gap-3 items-start transition-all duration-300">
        <i class="ri-information-fill text-info text-xl shrink-0 mt-0.5"></i>
        <div class="w-full">
            <div 
                class="flex justify-between items-center cursor-pointer select-none" 
                @click="isExpanded = !isExpanded"
            >
                <h4 v-if="title" class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ title }}</h4>
                <i :class="['text-gray-500 transition-transform duration-300 text-lg', isExpanded ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line']"></i>
            </div>
            <div v-show="isExpanded" class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed space-y-2 mt-2">
                <slot />
            </div>
        </div>
    </div>
</template>